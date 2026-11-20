package cloud.poptracker.farm

import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import cloud.poptracker.farm.ipc.RegionContract
import cloud.poptracker.farm.ipc.TestBatchContract as C
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.Callable
import java.util.concurrent.ExecutionException
import java.util.concurrent.FutureTask
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException
import java.util.concurrent.atomic.AtomicBoolean

internal class TestBatchClient(private val context: Context) {
    private val running = AtomicBoolean()
    private val uri = Uri.parse("content://${C.AUTHORITY}")

    fun query(payload: JSONObject, nonce: String, fault: String? = null, timeoutMs: Long = 5000): JSONObject {
        val scope = C.parse(payload.toString())
        require(RegionContract.requestIdPattern.matches(nonce) && timeoutMs in 1..5000)
        if (context.packageManager.resolveContentProvider(C.AUTHORITY, 0)?.packageName != RegionContract.PROVIDER ||
            context.packageManager.checkSignatures(context.packageName, RegionContract.PROVIDER) != PackageManager.SIGNATURE_MATCH) {
            throw TaskProblem("EXECUTION_FAILED", "test_provider_unavailable")
        }
        if (!running.compareAndSet(false, true)) throw TaskProblem("EXECUTION_FAILED", "test_provider_busy")
        val future = FutureTask(Callable {
            try {
                val request = Bundle().apply {
                    putString("batch", payload.toString()); putString("scope_sha256", scope.digest); putString("nonce", nonce)
                    if (fault != null) putString("fault", fault)
                }
                val provider = context.contentResolver.acquireUnstableContentProviderClient(uri)
                    ?: error("Provider unavailable")
                provider.use { it.call("observe_batch_v1", null, request)?.getString("response") }
            } finally { running.set(false) }
        })
        Thread(future, "farm-test-batch").apply { isDaemon = true }.start()
        val raw = try { future.get(timeoutMs, TimeUnit.MILLISECONDS) }
        catch (_: TimeoutException) { throw TaskProblem("EXECUTION_FAILED", "test_provider_timeout") }
        catch (_: ExecutionException) { throw TaskProblem("EXECUTION_FAILED", "test_provider_call_failed") }
        return try { validate(requireNotNull(raw), scope, nonce) }
        catch (_: Exception) { throw TaskProblem("EXECUTION_FAILED", "invalid_test_provider_reply") }
    }

    fun execute(job: JSONObject): JSONObject {
        if (job.opt("type") != C.TASK || job.opt("schema_version") != 1 || job.opt("result_protocol") != 2) {
            throw TaskProblem("UNSUPPORTED_TASK", "Unsupported synthetic batch protocol")
        }
        val payload = job.getJSONObject("payload")
        if (payload.opt("request_id") != job.getString("task_id")) throw TaskProblem("INVALID_PAYLOAD", "Batch request differs from task")
        return query(payload, job.getString("attempt_id"))
    }

    private fun validate(raw: String, scope: C.Scope, nonce: String): JSONObject {
        require(raw.length in 1..32768)
        val reply = JSONObject(raw)
        require(reply.get("version") == 1 && reply.get("request_id") == scope.id && reply.get("nonce") == nonce &&
            reply.get("scope_sha256") == scope.digest && reply.get("source") == C.SOURCE)
        val observations = reply.getJSONArray("observations")
        require(observations.length() == scope.members.size)
        val expected = scope.members.associateBy { it.uid }.toMutableMap()
        val checked = JSONArray()
        for (i in 0 until observations.length()) {
            val row = observations.getJSONObject(i)
            val member = requireNotNull(expected.remove(row.get("uid") as String))
            require(row.get("vid") == member.vid && row.get("field") == "author.region" &&
                row.get("raw_region") == member.region && row.get("typed_region") == member.region &&
                row.get("observed_ms") is Long && row.getLong("observed_ms") in 1..System.currentTimeMillis() &&
                (row.get("evidence_sha256") as String).matches(Regex("[0-9a-f]{64}")))
            checked.put(JSONObject().put("uid", member.uid).put("vid", member.vid).put("role", member.role)
                .put("region", member.region).put("observed_ms", row.getLong("observed_ms"))
                .put("evidence_sha256", row.getString("evidence_sha256")))
        }
        require(expected.isEmpty())
        return JSONObject().put("request_id", scope.id).put("scope_sha256", scope.digest)
            .put("source", C.SOURCE).put("synthetic", true).put("observations", checked)
    }
}

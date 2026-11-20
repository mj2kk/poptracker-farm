package cloud.poptracker.farm

import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import java.util.concurrent.Callable
import java.util.concurrent.FutureTask
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException
import java.util.concurrent.atomic.AtomicBoolean
import org.json.JSONArray
import org.json.JSONObject

internal const val FRESH_GEO = "FRESH_AUTHOR_GEO_V1"

internal class FreshGeoClient(private val context: Context) {
    private val executor = "cloud.poptracker.farm.geoexecutor"
    private val uri = Uri.parse("content://$executor")

    private fun trusted(): Boolean {
        val pm = context.packageManager
        return pm.resolveContentProvider(executor, 0)?.packageName == executor &&
            pm.checkSignatures(context.packageName, executor) == PackageManager.SIGNATURE_MATCH
    }

    fun ready(): Boolean {
        if (!trusted() || !probing.compareAndSet(false, true)) return false
        val future = FutureTask(Callable {
            try {
                context.contentResolver.call(uri, "ready_v1", null, null)?.getBoolean("ready") == true
            } finally { probing.set(false) }
        })
        Thread(future, "farm-geo-ready").apply { isDaemon = true }.start()
        return runCatching { future.get(2, TimeUnit.SECONDS) }.getOrDefault(false)
    }

    companion object {
        private val running = AtomicBoolean()
        private val probing = AtomicBoolean()
    }

    fun execute(job: JSONObject): JSONObject {
        if (!running.compareAndSet(false, true)) throw TaskProblem("EXECUTION_FAILED", "geo_executor_busy")
        val future = FutureTask(Callable {
            try { observe(job) } finally { running.set(false) }
        })
        Thread(future, "farm-geo-ipc").apply { isDaemon = true }.start()
        return try {
            future.get(90, TimeUnit.SECONDS)
        } catch (_: TimeoutException) {
            throw TaskProblem("EXECUTION_FAILED", "geo_executor_timeout")
        } catch (e: java.util.concurrent.ExecutionException) {
            throw (e.cause ?: e)
        }
    }

    private fun observe(job: JSONObject): JSONObject {
        if (!trusted()) throw TaskProblem("EXECUTION_FAILED", "geo_executor_unavailable")
        if (job.opt("schema_version") != 1 || job.opt("result_protocol") != 2)
            throw TaskProblem("UNSUPPORTED_TASK", "unsupported_geo_protocol")
        val scope = job.getJSONObject("payload")
        val taskId = job.getString("task_id")
        if (scope.opt("request_id") != taskId) throw TaskProblem("INVALID_PAYLOAD", "geo_request_mismatch")
        val reply = context.contentResolver.call(uri, "observe_batch_v1", null,
            Bundle().apply { putString("batch", scope.toString()) }) ?: throw TaskProblem("EXECUTION_FAILED","empty_geo_receipt")
        if (reply.getString("request_id") != taskId || reply.getString("scope_sha256") != scope.getString("scope_sha256"))
            throw TaskProblem("EXECUTION_FAILED", "geo_scope_mismatch")
        if (reply.getString("status") != "verified_reply")
            throw TaskProblem("EXECUTION_FAILED", when(reply.getString("status")){
                "source_unavailable" -> "geo_source_unavailable"
                "busy" -> "geo_executor_busy"
                else -> "geo_source_failed"
            })
        return JSONObject().put("request_id",taskId).put("scope_sha256",reply.getString("scope_sha256"))
            .put("observations",JSONArray(requireNotNull(reply.getString("observations"))))
    }
}

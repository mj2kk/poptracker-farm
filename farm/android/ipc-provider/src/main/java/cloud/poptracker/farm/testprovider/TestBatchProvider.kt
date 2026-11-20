package cloud.poptracker.farm.testprovider

import android.content.ContentProvider
import android.content.ContentValues
import android.content.Context
import android.content.pm.PackageManager
import android.database.Cursor
import android.net.Uri
import android.os.Binder
import android.os.Bundle
import android.os.Process
import cloud.poptracker.farm.ipc.RegionContract
import cloud.poptracker.farm.ipc.TestBatchContract as C
import org.json.JSONArray
import org.json.JSONObject

/** Controlled fixture provider. Its package has no INTERNET permission. */
class TestBatchProvider : ContentProvider() {
    private val lock = Any()
    override fun onCreate() = true

    override fun call(method: String, arg: String?, extras: Bundle?): Bundle {
        val ctx = requireNotNull(context)
        val caller = Binder.getCallingUid()
        val signed = ctx.packageManager.checkSignatures(caller, Process.myUid()) == PackageManager.SIGNATURE_MATCH
        if (!callerAllowed(signed, ctx.packageManager.getPackagesForUid(caller))) throw SecurityException("Untrusted test caller")
        require(arg == null)
        val identity = Binder.clearCallingIdentity()
        try {
            val prefs = ctx.getSharedPreferences("batch_receipts", Context.MODE_PRIVATE)
            if (method == "stats_v1") return Bundle().apply {
                putInt("pid", Process.myPid()); putInt("work_count", prefs.getInt("work_count", 0))
            }
            require(method == "observe_batch_v1")
            val supplied = requireNotNull(extras)
            val scope = C.parse(requireNotNull(supplied.getString("batch")))
            val nonce = requireNotNull(supplied.getString("nonce"))
            require(RegionContract.requestIdPattern.matches(nonce) && supplied.getString("scope_sha256") == scope.digest)
            when (supplied.getString("fault")) {
                null -> Unit
                "error" -> throw IllegalStateException("synthetic_provider_error")
                "delay" -> Thread.sleep(1500)
                "kill" -> { Process.killProcess(Process.myPid()); error("process termination failed") }
                "malformed" -> return Bundle().apply { putString("response", "{broken") }
                else -> error("Unknown test fault")
            }
            synchronized(lock) {
                val key = "receipt_${scope.id}"
                val old = prefs.getString(key, null)
                if (old != null) {
                    val reply = JSONObject(old)
                    require(reply.getString("scope_sha256") == scope.digest && reply.getString("nonce") == nonce) { "request_conflict" }
                    return Bundle().apply { putString("response", old); putBoolean("replayed", true) }
                }
                val observed = System.currentTimeMillis()
                val records = JSONArray().apply { scope.members.forEach { member ->
                    put(JSONObject().put("uid", member.uid).put("vid", member.vid).put("field", "author.region")
                        .put("raw_region", member.region).put("typed_region", member.region).put("observed_ms", observed)
                        .put("evidence_sha256", C.sha("synthetic|${scope.id}|${member.uid}|${member.vid}|${member.region}|$observed".toByteArray())))
                } }
                val reply = JSONObject().put("version", 1).put("request_id", scope.id).put("nonce", nonce)
                    .put("scope_sha256", scope.digest).put("source", C.SOURCE).put("observations", records).toString()
                check(prefs.edit().putString(key, reply).putInt("work_count", prefs.getInt("work_count", 0) + 1).commit())
                return Bundle().apply { putString("response", reply); putBoolean("replayed", false) }
            }
        } finally { Binder.restoreCallingIdentity(identity) }
    }
    override fun query(u: Uri, p: Array<out String>?, s: String?, a: Array<out String>?, o: String?): Cursor? = throw UnsupportedOperationException()
    override fun insert(u: Uri, v: ContentValues?): Uri? = throw UnsupportedOperationException()
    override fun update(u: Uri, v: ContentValues?, s: String?, a: Array<out String>?): Int = throw UnsupportedOperationException()
    override fun delete(u: Uri, s: String?, a: Array<out String>?): Int = throw UnsupportedOperationException()
    override fun getType(u: Uri): String? = null
}

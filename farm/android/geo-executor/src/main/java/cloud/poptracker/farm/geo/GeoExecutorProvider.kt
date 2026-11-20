package cloud.poptracker.farm.geo

import android.content.ContentProvider
import android.content.ContentValues
import android.content.pm.PackageManager
import android.database.Cursor
import android.net.Uri
import android.os.Binder
import android.os.Bundle
import android.os.Process
import org.json.JSONObject
import java.util.UUID

/** Receives explicit batches from our Farm Agent and forwards them to the research TikTok APK. */
class GeoExecutorProvider : ContentProvider() {
    override fun onCreate() = true

    // Research APK that contains FullAuthorWorker + queryBatchAweme interception.
    // Signed with a different certificate than Farm — signature match is intentionally not required.
    private val sourcePackage = "com.zhiliaoapp.musically.poptrackergeo"
    private val sourceUri = Uri.parse("content://cloud.poptracker.farm.regionsource")

    private val mutex = java.util.concurrent.atomic.AtomicBoolean(false)

    override fun call(method: String, arg: String?, extras: Bundle?): Bundle {
        val ctx = requireNotNull(context)
        val pm = ctx.packageManager
        val caller = Binder.getCallingUid()
        val packages = pm.getPackagesForUid(caller).orEmpty()
        if (caller != Process.myUid() && !("cloud.poptracker.farm" in packages &&
                pm.checkSignatures(Process.myUid(), caller) == PackageManager.SIGNATURE_MATCH)) {
            throw SecurityException("Farm caller required")
        }

        if (method == "ready_v1" && arg == null) {
            val identity = Binder.clearCallingIdentity()
            val ready = try {
                val provider = pm.resolveContentProvider(requireNotNull(sourceUri.authority), 0)
                provider?.packageName == sourcePackage &&
                    ctx.contentResolver.call(sourceUri, "ready_v1", null, null)?.getBoolean("ready") == true
            } catch (_: Exception) {
                false
            } finally {
                Binder.restoreCallingIdentity(identity)
            }
            return Bundle().apply { putBoolean("ready", ready) }
        }

        require(method == "observe_batch_v1" && arg == null)
        val raw = requireNotNull(extras?.getString("batch"))
        val batch = parseBatch(raw)
        val result = Bundle().apply {
            putString("request_id", batch.requestId)
            putString("scope_sha256", batch.digest)
            putInt("target_count", batch.targetCount)
            putInt("control_count", controls.size)
            putLong("received_ms", System.currentTimeMillis())
            putInt("source_calls", 0)
        }
        if (!mutex.compareAndSet(false, true)) return result.apply { putString("status", "busy") }

        val identity = Binder.clearCallingIdentity()
        try {
            val info = pm.resolveContentProvider(requireNotNull(sourceUri.authority), 0)
            if (info == null || info.packageName != sourcePackage) {
                return result.apply { putString("status", "source_unavailable") }
            }
            // Certificate check intentionally removed — research APK is signed differently.
            val client = ctx.contentResolver.acquireUnstableContentProviderClient(sourceUri)
                ?: return result.apply { putString("status", "source_unavailable") }
            client.use {
                val nonce = UUID.randomUUID().toString()
                val start = System.currentTimeMillis()
                val query = Bundle().apply {
                    putString("batch", raw)
                    putString("scope_sha256", batch.digest)
                    putString("nonce", nonce)
                }
                result.putInt("source_calls", 1)
                val reply = it.call(method, null, query)?.getString("response")
                require(reply != null && reply.length <= 32768)
                val checked = checkedObservations(batch, JSONObject(reply), nonce, start, System.currentTimeMillis())
                result.putString("observations", checked.toString())
                result.putString("status", "verified_reply")
            }
        } catch (e: Exception) {
            result.putString("status", "source_failed")
        } finally {
            Binder.restoreCallingIdentity(identity)
            mutex.set(false)
        }
        return result
    }

    override fun query(uri: Uri, projection: Array<out String>?, selection: String?, selectionArgs: Array<out String>?, sortOrder: String?): Cursor? = throw UnsupportedOperationException()
    override fun insert(uri: Uri, values: ContentValues?): Uri? = throw UnsupportedOperationException()
    override fun update(uri: Uri, values: ContentValues?, selection: String?, selectionArgs: Array<out String>?): Int = throw UnsupportedOperationException()
    override fun delete(uri: Uri, selection: String?, selectionArgs: Array<out String>?): Int = throw UnsupportedOperationException()
    override fun getType(uri: Uri): String? = null
}

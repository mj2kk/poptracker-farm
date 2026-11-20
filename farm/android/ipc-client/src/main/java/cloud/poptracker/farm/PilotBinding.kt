package cloud.poptracker.farm

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.ServiceConnection
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.IBinder
import android.os.Looper
import android.os.RemoteException
import cloud.poptracker.farm.ipc.ITestRegion
import cloud.poptracker.farm.ipc.RegionContract as Contract
import org.json.JSONObject
import java.util.concurrent.CompletableFuture
import java.util.concurrent.ExecutionException
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException

// ServiceConnection callbacks use the main thread; the Farm worker waits off that thread.
internal fun <T> withTestProvider(context: Context, use: (ITestRegion) -> T): T {
    check(Looper.myLooper() != Looper.getMainLooper())
    if (context.packageManager.checkSignatures(context.packageName, Contract.PROVIDER) != PackageManager.SIGNATURE_MATCH) {
        throw TaskProblem("EXECUTION_FAILED", "Provider missing or signer mismatch")
    }
    val component = ComponentName(Contract.PROVIDER, Contract.SERVICE)
    val connected = CompletableFuture<IBinder>()
    val connection = object : ServiceConnection {
        override fun onServiceConnected(name: ComponentName, binder: IBinder) {
            if (name == component) connected.complete(binder)
            else connected.completeExceptionally(RemoteException("Unexpected component"))
        }
        override fun onServiceDisconnected(name: ComponentName) { connected.completeExceptionally(RemoteException()) }
        override fun onBindingDied(name: ComponentName) { connected.completeExceptionally(RemoteException()) }
        override fun onNullBinding(name: ComponentName) { connected.completeExceptionally(RemoteException()) }
    }
    var bound = false
    try {
        bound = context.bindService(Intent().setComponent(component), connection, Context.BIND_AUTO_CREATE)
        if (!bound) throw TaskProblem("EXECUTION_FAILED", "Provider unavailable")
        val binder = connected.get(5, TimeUnit.SECONDS)
        return use(ITestRegion.Stub.asInterface(binder))
    } catch (_: TimeoutException) {
        throw TaskProblem("EXECUTION_FAILED", "Provider binding timed out")
    } catch (_: SecurityException) {
        throw TaskProblem("EXECUTION_FAILED", "Provider denied caller")
    } catch (_: ExecutionException) {
        throw TaskProblem("EXECUTION_FAILED", "Provider disconnected")
    } catch (_: RemoteException) {
        throw TaskProblem("EXECUTION_FAILED", "Provider transaction interrupted")
    } finally {
        if (bound) context.unbindService(connection)
    }
}

internal fun executeIpcTask(context: Context, job: JSONObject): JSONObject {
    if (job.opt("type") != Contract.CAPABILITY || job.opt("schema_version") != 1 || job.opt("result_protocol") != 2) {
        throw TaskProblem("UNSUPPORTED_TASK", "Expected a v1 synthetic IPC task with delivery protocol 2")
    }
    val payload = job.optJSONObject("payload") ?: throw TaskProblem("INVALID_PAYLOAD", "Missing payload")
    if (payload.keys().asSequence().toSet() != setOf("author_uid") || payload.opt("author_uid") != Contract.FIXTURE) {
        throw TaskProblem("INVALID_PAYLOAD", "Only the synthetic author fixture is accepted")
    }
    val requestId = job.getString("task_id")
    val reply = withTestProvider(context) { service ->
        service.query(Contract.VERSION, requestId, Contract.OPERATION, Contract.FIXTURE)
    }
    return validatePilotReply(reply, requestId)
}

internal fun validatePilotReply(reply: Bundle?, requestId: String): JSONObject {
    val fields = setOf("request_id", "status", "author_uid", "source", "region")
    if (reply == null || reply.keySet() != fields || reply.getString("status") != "success"
        || reply.getString("request_id") != requestId || reply.getString("author_uid") != Contract.FIXTURE
        || reply.getString("source") != Contract.SOURCE || !Regex("[A-Z]{2}").matches(reply.getString("region").orEmpty())) {
        throw TaskProblem("EXECUTION_FAILED", "Invalid or mismatched provider reply")
    }
    return JSONObject().apply { fields.forEach { put(it, reply.getString(it)) } }
}

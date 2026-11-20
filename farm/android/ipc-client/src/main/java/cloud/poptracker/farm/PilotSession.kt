package cloud.poptracker.farm

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import cloud.poptracker.farm.ipc.RegionContract
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.Executors

// Test prefix is fixed before the shared DeviceApi appends /api/device/v1/.
private const val PILOT_ORIGIN = DEFAULT_ORIGIN + "/step05"

internal class PilotSession(private val context: Context) {
    private val vault = Vault(context)
    private val main = Handler(Looper.getMainLooper())
    private val worker = Executors.newSingleThreadExecutor()
    private var credentials: Credentials? = null
    private var failures = 0
    private var heartbeatDue = 0L
    private var listener: ((String, Boolean) -> Unit)? = null
    @Volatile private var active = false
    private var busy = false
    private var display = "Введите одноразовый код тестового контура."
    private var enrolled = false
    private val tick = Runnable { run() }
    private val batches = TestBatchClient(context)
    private val engine = TaskEngine({ vault.readSealed("ipc_task") }, { vault.writeSealed("ipc_task", it) },
        ::utcNow, SystemClock::elapsedRealtime) { job ->
        if (job.opt("type") == cloud.poptracker.farm.ipc.TestBatchContract.TASK) batches.execute(job)
        else executeIpcTask(context, job)
    }

    fun attach(callback: (String, Boolean) -> Unit) {
        active = true
        listener = callback
        render()
        main.removeCallbacks(tick)
        main.post(tick)
    }

    fun detach() {
        active = false
        listener = null
        main.removeCallbacks(tick)
    }

    private fun render() { listener?.invoke(display, enrolled) }

    fun enroll(code: String) {
        if (busy || enrolled) return
        if (!Regex("[a-fA-F0-9]{16}").matches(code)) {
            display = "Нужен код из 16 шестнадцатеричных символов."
            render()
            return
        }
        run(code)
    }

    private fun run(code: String? = null) {
        if (!active || busy) return
        busy = true
        worker.execute {
            var registration = false
            val result = runCatching {
                if (credentials == null) credentials = vault.read()
                if (code != null && credentials == null) {
                    val reply = request(PILOT_ORIGIN, "enroll", deviceFields().put("code", code))
                    val new = Credentials(DEFAULT_ORIGIN, reply.getString("device_id"), reply.getString("token"))
                    vault.save(new)
                    credentials = new
                }
                val c = credentials ?: return@runCatching "Введите одноразовый код тестового контура."
                registration = true
                check(c.origin == DEFAULT_ORIGIN)
                if (SystemClock.elapsedRealtime() >= heartbeatDue) {
                    request(PILOT_ORIGIN, "heartbeat", heartbeatFields(c.id, null, utcNow()), c.token)
                    heartbeatDue = SystemClock.elapsedRealtime() + 60_000L
                }
                engine.step(claim = {
                    val claim = JSONObject().put("device_id", c.id).put("agent_version", BuildConfig.VERSION_NAME)
                        .put("result_protocol", 2).put("capabilities", JSONArray().put(RegionContract.CAPABILITY).put(cloud.poptracker.farm.ipc.TestBatchContract.TASK))
                    requestJson(PILOT_ORIGIN, "tasks/claim", claim, c.token)
                }, send = { id, body -> request(PILOT_ORIGIN, "tasks/$id/result", body, c.token) }, active = { active })
                "Vuka · " + c.id + "\n" + taskSummary(vault.readSealed("ipc_task"))
            }
            main.post {
                busy = false
                enrolled = registration
                var retry: Throwable? = null
                result.onSuccess { failures = 0; display = it }.onFailure { error ->
                    failures = (failures + 1).coerceAtMost(8)
                    retry = error
                    display = when (error) {
                        is ApiFailure -> "Тестовый сервер · HTTP " + error.status
                        else -> "Тестовый клиент · " + error.javaClass.simpleName
                    }
                }
                render()
                main.removeCallbacks(tick)
                if (active && registration) {
                    main.postDelayed(tick, retry?.let { taskPollDelay(it, failures) } ?: 4_000L)
                }
            }
        }
    }
}

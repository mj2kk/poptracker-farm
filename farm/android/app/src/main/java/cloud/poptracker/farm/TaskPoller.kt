package cloud.poptracker.farm

import android.content.Context
import android.os.BatteryManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import org.json.JSONObject
import java.util.concurrent.Executors

internal class TaskPoller(context: Context, vault: Vault, private val changed: () -> Unit,
                          private val unauthorized: () -> Unit) {
    private val geo = FreshGeoClient(context.applicationContext)
    private val main = Handler(Looper.getMainLooper())
    private val worker = Executors.newSingleThreadExecutor()
    private val battery = context.getSystemService(Context.BATTERY_SERVICE) as BatteryManager
    @Volatile private var active: Credentials? = null
    private var failures = 0
    private var nextPoll = 0L
    private var restored = false
    @Volatile private var pendingReply = false
    var busy = false
        private set
    var summary = ""
        private set
    var error = ""
        private set
    private val engine = TaskEngine({
        val snapshot = vault.readSealed("task")
        pendingReply = snapshot?.optJSONObject("pending")?.has("body") == true
        snapshot
    }, { snapshot ->
        vault.writeSealed("task", snapshot)
        pendingReply = snapshot.optJSONObject("pending")?.has("body") == true
        val text = taskSummary(snapshot)
        main.post { summary = text; changed() }
    }, ::utcNow, SystemClock::elapsedRealtime, { id, data -> vault.writeSealed("collection_rejected_$id", data) }) { job ->
        if (job.optString("type") == FRESH_GEO) geo.execute(job) else executeTask(job, ::deviceStatus, ::utcNow, SystemClock::elapsedRealtime, Thread::sleep)
    }
    private val initialState = { taskSummary(vault.readSealed("task")) }
    private val tick = Runnable { poll() }

    fun start(c: Credentials) {
        active = c
        schedule()
    }
    fun stop() { active = null; main.removeCallbacks(tick) }
    fun reset() {
        stop()
        restored = false
        pendingReply = false
        failures = 0
        nextPoll = 0L
        summary = ""
        error = ""
    }
    private fun schedule() {
        main.removeCallbacks(tick)
        if (active != null && !busy) main.postDelayed(tick, (nextPoll - SystemClock.elapsedRealtime()).coerceAtLeast(0))
    }
    private fun deviceStatus(): JSONObject {
        val level = battery.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY).takeIf { it in 0..100 }
        return deviceFields().put("manufacturer",Build.MANUFACTURER).put("model",Build.MODEL)
            .put("api_level",Build.VERSION.SDK_INT).put("battery_percent",level).put("is_charging",battery.isCharging)
    }
    private fun poll() {
        val c = active ?: return
        if (busy) return
        busy = true
        val restore = !restored
        changed()
        worker.execute {
            val outcome = runCatching {
                if (restore) {
                    val text = initialState()
                    main.post { summary = text; restored = true; changed() }
                }
                engine.step(
                    claim = { requestJson(c.origin,"tasks/claim",JSONObject().put("device_id",c.id)
                        .put("agent_version",BuildConfig.VERSION_NAME).put("result_protocol",2)
                        .put("capabilities",org.json.JSONArray().put(UI_SCENARIO).put(COLLECTION_TYPE).put(ARCHIVE_COMMENTS).apply { if (geo.ready()) put(FRESH_GEO) }),c.token) },
                    send = { id, body -> request(c.origin,"tasks/$id/result",body,c.token) },
                    active = { active === c })
            }
            main.post {
                busy = false
                val e = outcome.exceptionOrNull()
                val authError = e is ApiFailure && e.status == 401
                val delay = taskPollDelay(e, failures, pendingReply)
                if (e == null) {
                    failures = 0
                } else {
                    error = "Последняя ошибка (" + utcNow() + "): " + when (e) {
                        is ApiFailure -> "Задания: HTTP " + e.status
                        is java.io.IOException -> "Задания: нет связи с сервером"
                        else -> "Задания: ошибка протокола или хранилища"
                    }
                    failures = (failures + 1).coerceAtMost(8)
                }
                nextPoll = SystemClock.elapsedRealtime() + delay
                if (authError) unauthorized()
                changed()
                schedule()
            }
        }
    }
}

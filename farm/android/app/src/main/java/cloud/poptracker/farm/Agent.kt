package cloud.poptracker.farm

import android.content.Context
import android.hardware.display.DisplayManager
import android.view.Display
import org.json.JSONObject
import android.os.BatteryManager
import android.os.Handler
import android.os.Looper
import java.util.concurrent.Executors
import javax.net.ssl.SSLException

// One owner per process keeps rotation and activity transitions from duplicating requests.
internal class Agent private constructor(private val context: Context) {
    companion object {
        private var shared: Agent? = null
        fun get(context: Context): Agent = shared ?: Agent(context.applicationContext).also { shared = it }
    }
    private val vault = Vault(context)
    private val main = Handler(Looper.getMainLooper())
    private val worker = Executors.newSingleThreadExecutor()
    private val tick = Runnable { heartbeat() }
    private var listener: (() -> Unit)? = null
    private var failures = 0
    private var verified = false
    var blocked = false
        private set
    var busy = false
        private set
    var message = "Введите одноразовый код с сервера."
        private set
    var current: Credentials? = try { vault.read() } catch (_: Exception) {
        blocked = true
        message = "Хранилище недоступно. Забудьте устройство и получите новый код."
        null
    }
        private set
    private val tasks = TaskPoller(context, vault, { listener?.invoke() }, ::authRejected)
    private val displays = context.getSystemService(Context.DISPLAY_SERVICE) as DisplayManager
    private fun screenOn(): Boolean? = when (displays.getDisplay(Display.DEFAULT_DISPLAY)?.state) {
        Display.STATE_ON, Display.STATE_VR, Display.STATE_ON_SUSPEND -> true
        Display.STATE_OFF, Display.STATE_DOZE, Display.STATE_DOZE_SUSPEND -> false
        else -> null
    }
    private var observedScreen = screenOn()
    init {
        // Listen while the process lives, including the transition that stops the Activity.
        // Display events do not extend the task worker's online lease.
        displays.registerDisplayListener(object : DisplayManager.DisplayListener {
            override fun onDisplayAdded(id: Int) = Unit
            override fun onDisplayRemoved(id: Int) = Unit
            override fun onDisplayChanged(id: Int) {
                if (id != Display.DEFAULT_DISPLAY) return
                val state = screenOn()
                if (observedScreen == state) return
                observedScreen = state
                val c = current ?: return
                if (blocked || state == null) return
                worker.execute {
                    val latest = screenOn() ?: return@execute
                    runCatching { request(c.origin, "display", JSONObject().put("device_id", c.id).put("screen_on", latest), c.token) }
                }
            }
        }, main)
    }

    val taskSummary get() = listOf(tasks.summary, tasks.error).filter { it.isNotEmpty() }.joinToString("\n")
    val canForget get() = !busy && !tasks.busy
    private fun authRejected() {
        blocked = true
        tasks.stop()
        main.removeCallbacks(tick)
        message = ApiFailure(401).message.orEmpty()
    }
    val lastBeat get() = vault.lastBeat
    fun attach(callback: () -> Unit) {
        listener = callback
        callback()
        if (!blocked) current?.let {
            if (!busy) main.post(tick)
            tasks.start(it)
        }
    }
    fun detach() { listener = null; main.removeCallbacks(tick); tasks.stop() }

    fun connect(address: String, code: String) {
        if (busy || current != null || blocked) return
        val origin = try { validatedOrigin(address) } catch (_: Exception) {
            message = "Введите HTTPS-адрес сервера без пути."; listener?.invoke(); return
        }
        if (!code.matches(Regex("[A-Fa-f0-9]{16}"))) {
            message = "Нужен код из 16 шестнадцатеричных символов."; listener?.invoke(); return
        }
        busy = true
        message = "Регистрация…"
        listener?.invoke()
        worker.execute {
            val result = runCatching {
                val reply = request(origin, "enroll", deviceFields().put("code", code))
                Credentials(origin, reply.getString("device_id"), reply.getString("token")).also(vault::save)
            }
            main.post {
                busy = false
                result.onSuccess {
                    current = it
                    message = "Устройство зарегистрировано."
                    if (listener != null) { main.post(tick); tasks.start(it) }
                }.onFailure { message = errorText(it) }
                listener?.invoke()
            }
        }
    }

    private fun heartbeat() {
        val c = current ?: return
        if (busy || blocked || listener == null) return
        busy = true
        val checkIdentity = !verified
        worker.execute {
            val result = runCatching {
                if (checkIdentity) check(request(c.origin, "me", token = c.token).getString("device_id") == c.id)
                val manager = context.getSystemService(Context.BATTERY_SERVICE) as BatteryManager
                val battery = manager.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY).takeIf { it in 0..100 }
                request(c.origin, "heartbeat", heartbeatFields(c.id, battery, utcNow()).put("screen_on", screenOn()), c.token).getString("received_at")
            }
            main.post {
                busy = false
                val delay = result.fold(onSuccess = {
                    verified = true
                    failures = 0
                    vault.lastBeat = it
                    if (!blocked) message = "Подключено"
                    60_000L
                }, onFailure = {
                    if (it is ApiFailure && it.status in listOf(401, 403)) authRejected()
                    if (!blocked) message = errorText(it)
                    if (it is ApiFailure && it.status == 429) 60_000L else retryMillis(failures++)
                })
                listener?.invoke()
                if (listener != null && !blocked) main.postDelayed(tick, delay)
            }
        }
    }

    fun forget() {
        if (!canForget) return
        main.removeCallbacks(tick)
        try {
            tasks.reset()
            vault.clear()
            current = null
            verified = false
            failures = 0
            blocked = false
            message = "Локальная регистрация удалена."
        } catch (_: Exception) { message = "Не удалось очистить хранилище." }
        listener?.invoke()
    }

    private fun errorText(error: Throwable) = when (error) {
        is ApiFailure -> error.message ?: "Ошибка API."
        is SSLException -> "Не удалось проверить HTTPS-соединение."
        is java.io.IOException -> "Нет связи с сервером."
        else -> "Ошибка данных или защищённого хранилища."
    }
}

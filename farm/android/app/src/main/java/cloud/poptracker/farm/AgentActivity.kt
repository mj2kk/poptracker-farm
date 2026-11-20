package cloud.poptracker.farm

import android.app.Activity
import android.app.AlertDialog
import android.os.Bundle
import android.text.InputType
import android.view.View
import android.view.WindowManager
import android.widget.*

class AgentActivity : Activity() {
    private lateinit var home: View
    private lateinit var serviceStatus: TextView
    private var testScreen: AutomationTestScreen? = null
    private lateinit var agent: Agent
    private val setupViews = mutableListOf<View>()
    private lateinit var address: EditText
    private lateinit var code: EditText
    private lateinit var connect: Button
    private lateinit var forget: Button
    private lateinit var status: TextView
    private lateinit var identity: TextView
    private lateinit var tasks: TextView
    private lateinit var last: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        agent = Agent.get(this)
        val gap = (20 * resources.displayMetrics.density).toInt()
        val scroll = ScrollView(this)
        val form = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(gap, gap, gap, gap)
            isFocusableInTouchMode = true
        }
        scroll.setOnApplyWindowInsetsListener { v, insets ->
            @Suppress("DEPRECATION")
            v.setPadding(0, insets.systemWindowInsetTop, 0, insets.systemWindowInsetBottom)
            insets
        }
        scroll.addView(form)
        fun label(text: String, size: Float = 16f) = TextView(this).apply {
            this.text = text
            textSize = size
            setPadding(0, gap / 2, 0, 0)
            form.addView(this)
        }
        label("PopTracker Farm", 27f)
        form.addView(Button(this).apply {
            text = "Открыть тестовый экран"
            id = R.id.automation_test
            setOnClickListener { openTest() }
        })
        setupViews.add(label("Адрес сервера"))
        address = EditText(this).apply {
            id = R.id.server_address
            setSingleLine()
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
            setText(agent.current?.origin ?: DEFAULT_ORIGIN)
            form.addView(this)
        }
        setupViews.add(label("Одноразовый код"))
        code = EditText(this).apply {
            id = R.id.enrollment_code
            setSingleLine()
            isSaveEnabled = false
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            if (android.os.Build.VERSION.SDK_INT >= 26) importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO
            form.addView(this)
        }
        connect = Button(this).apply {
            id = R.id.connect
            text = "Подключить"
            setOnClickListener { agent.connect(address.text.toString(), code.text.toString().trim()) }
            form.addView(this)
        }
        setupViews.addAll(listOf(address, code, connect))
        status = label("")
        identity = label("")
        last = label("")
        tasks = label("")
        forget = Button(this).apply {
            text = "Забыть устройство"
            setOnClickListener {
                AlertDialog.Builder(this@AgentActivity)
                    .setMessage("Удалить регистрацию с этого телефона? Для подключения понадобится новый код.")
                    .setNegativeButton("Отмена", null)
                    .setPositiveButton("Удалить") { _, _ -> agent.forget() }.show()
            }
            form.addView(this)
        }
        serviceStatus = label("")
        form.addView(Button(this).apply {
            text = "Настройки специальных возможностей"
            setOnClickListener { startActivity(android.content.Intent(android.provider.Settings.ACTION_ACCESSIBILITY_SETTINGS)) }
        })
        label("Для теста включите службу PopTracker Farm в настройках. Она нажимает +1 и прокручивает список на экране Automation Test. Выключить её можно там же.")
        home = scroll
        setContentView(home)
        if (savedInstanceState?.getBoolean("automation_test_open") == true) openTest()
    }
    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putBoolean("automation_test_open",testScreen != null)
    }

    private fun openTest() {
        val screen = AutomationTestScreen(this, ::closeTest)
        testScreen = screen
        setContentView(screen.view)
        screen.show()
        screen.focused = hasWindowFocus()
        render()
    }
    private fun closeTest() {
        testScreen?.close()
        testScreen = null
        setContentView(home)
        render()
    }
    @Deprecated("Activity back navigation")
    override fun onBackPressed() { if (testScreen != null) closeTest() else super.onBackPressed() }
    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        testScreen?.focused = hasFocus
    }
    override fun onResume() {
        super.onResume()
        testScreen?.show()
        testScreen?.focused = hasWindowFocus()
        render()
    }
    override fun onPause() { testScreen?.close(); super.onPause() }
    override fun onStart() { super.onStart(); agent.attach(::render) }
    override fun onStop() { agent.detach(); super.onStop() }

    private fun render() {
        val enabled = OwnTestService.connected != null
        serviceStatus.text = if (enabled) "Служба теста включена" else "Служба теста выключена"
        testScreen?.accessibilityState(enabled)
        val current = agent.current
        val editable = current == null && !agent.busy && !agent.blocked
        setupViews.forEach { it.visibility = if (current == null) View.VISIBLE else View.GONE }
        address.isEnabled = editable
        code.isEnabled = editable
        connect.isEnabled = editable
        forget.isEnabled = agent.canForget
        forget.visibility = if (current != null || agent.blocked) View.VISIBLE else View.GONE
        if (current != null) {
            if (code.length() > 0) code.text.clear()
            if (address.text.toString() != current.origin) address.setText(current.origin)
        }
        status.text = agent.message
        tasks.visibility = if (current == null) View.GONE else View.VISIBLE
        tasks.text = agent.taskSummary
        identity.text = "ID устройства: " + (current?.id ?: "—")
        last.text = "Последний heartbeat (UTC): " + agent.lastBeat
    }
}

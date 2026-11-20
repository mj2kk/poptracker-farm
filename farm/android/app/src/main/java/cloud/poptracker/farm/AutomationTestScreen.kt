package cloud.poptracker.farm

import android.app.Activity
import android.graphics.Typeface
import android.widget.*
import android.view.ViewGroup.LayoutParams.MATCH_PARENT

internal class AutomationTestScreen(private val activity: Activity, back: () -> Unit) {
    companion object {
        @Volatile var open: AutomationTestScreen? = null
            private set
    }
    val view = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL; fitsSystemWindows = true }
    @Volatile var focused = false
    @Volatile var generation = 0
        private set
    private var value = 0
    private var running = false
    private val top = LinearLayout(activity)
    private val controls = LinearLayout(activity)
    private val counter = TextView(activity).apply { id = R.id.test_counter_value; text = "0"; textSize = 22f }
    private val status = TextView(activity).apply { id = R.id.test_scenario_status; text = "IDLE"; textSize = 18f }
    private val access = TextView(activity)
    private val list = ListView(activity).apply {
        id = R.id.test_list
        adapter = ArrayAdapter(activity,android.R.layout.simple_list_item_1,(1..32).map { "Test Item $it" })
    }
    private fun button(parent: LinearLayout, title: String, action: () -> Unit): Button =
        Button(activity).apply { text = title; isAllCaps = false; setOnClickListener { action() }; parent.addView(this) }

    private val resetButton: Button
    private val stopButton: Button
    init {
        val pad = (8 * activity.resources.displayMetrics.density).toInt()
        view.setPadding(pad,0,pad,0)
        view.addView(top)
        button(top,"Назад",back)
        top.addView(TextView(activity).apply { text = "Automation Test   "; textSize = 19f; setTypeface(null,Typeface.BOLD) })
        top.addView(status)
        view.addView(access)
        view.addView(controls)
        button(controls,"+1") { value++; counter.text = value.toString() }.id = R.id.test_increment_button
        controls.addView(counter)
        resetButton = button(controls,"Сброс") { if (!running) { reset(); finish("IDLE") } }.apply { id = R.id.test_reset }
        stopButton = button(controls,"Стоп") { if (running) { generation++; finish("FAILED") } }
        view.addView(list,LinearLayout.LayoutParams(MATCH_PARENT,0,1f))
        finish("IDLE")
    }
    fun show() { open = this }
    fun close() {
        if (open === this) open = null
        focused = false
        generation++
        if (running) finish("FAILED")
    }
    fun accessibilityState(enabled: Boolean) {
        val label = if (enabled) "Служба включена" else "Служба выключена"
        if (access.text != label) access.text = label
    }
    fun begin() { running = true; status.text = "RUNNING"; resetButton.isEnabled = false; stopButton.isEnabled = true; reset() }
    private fun reset() { value = 0; counter.text = "0"; list.setSelection(0) }
    fun finish(state: String) {
        running = false
        status.text = state
        resetButton.isEnabled = true
        stopButton.isEnabled = false
    }
}

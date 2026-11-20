package cloud.poptracker.farm

import android.app.Activity
import android.app.Application
import android.os.Bundle
import android.text.InputFilter
import android.text.InputType
import android.view.View
import android.view.WindowManager
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView

class PilotApplication : Application() {
    internal val pilot by lazy { PilotSession(this) }
}

class IpcPilotActivity : Activity() {
    private val pilot get() = (application as PilotApplication).pilot
    private lateinit var status: TextView
    private lateinit var code: EditText
    private lateinit var connect: Button

    override fun onCreate(state: Bundle?) {
        super.onCreate(state)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        val panel = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            val margin = (20 * resources.displayMetrics.density).toInt()
            setPadding(margin, margin * 2, margin, margin)
        }
        panel.addView(TextView(this).apply { text = "Farm · IPC Test"; textSize = 24f })
        code = EditText(this).apply {
            hint = "Код тестовой регистрации"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD
            filters = arrayOf(InputFilter.LengthFilter(16))
            isSingleLine = true
            importantForAutofill = View.IMPORTANT_FOR_AUTOFILL_NO
        }
        panel.addView(code)
        connect = Button(this).apply {
            text = "Подключить"
            setOnClickListener { pilot.enroll(code.text.toString().trim()) }
        }
        panel.addView(connect)
        status = TextView(this).apply { textSize = 17f; setTextIsSelectable(true) }
        panel.addView(status)
        setContentView(panel)
    }

    override fun onStart() {
        super.onStart()
        pilot.attach { message, registered ->
            status.text = message
            if (registered) code.setText("")
            code.visibility = if (registered) View.GONE else View.VISIBLE
            connect.visibility = code.visibility
        }
    }

    override fun onStop() { pilot.detach(); super.onStop() }
}

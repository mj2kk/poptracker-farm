package cloud.poptracker.farm

import android.content.Intent
import android.widget.Button
import android.widget.TextView
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class AutomationScreenTest {
    @Test fun ownScreenResetCounterAndDisabledService() {
        val inst = InstrumentationRegistry.getInstrumentation()
        val activity = inst.startActivitySync(Intent(inst.targetContext,AgentActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as AgentActivity
        try {
            inst.runOnMainSync {
                activity.findViewById<Button>(R.id.automation_test).performClick()
                val value = activity.findViewById<TextView>(R.id.test_counter_value)
                assertEquals("0",value.text.toString())
                activity.findViewById<Button>(R.id.test_increment_button).performClick()
                assertEquals("1",value.text.toString())
                activity.findViewById<Button>(R.id.test_reset).performClick()
                assertEquals("0",value.text.toString())
            }
            if (OwnTestService.connected == null) {
                val e = runCatching { runOwnUiScenario() }.exceptionOrNull() as TaskProblem
                assertEquals("ACCESSIBILITY_DISABLED",e.code)
            }
        } finally { inst.runOnMainSync { activity.finish() } }
    }
}

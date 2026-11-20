package cloud.poptracker.farm

import android.content.Intent
import android.os.SystemClock
import android.widget.Button
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class ScreenRestoreTest {
    @Test fun recreationRestoresExplicitlyOpenedTestAndResetsTransientRun() {
        val inst = InstrumentationRegistry.getInstrumentation()
        val activity = inst.startActivitySync(Intent(inst.targetContext,AgentActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as AgentActivity
        val monitor = inst.addMonitor(AgentActivity::class.java.name,null,false)
        try {
            inst.runOnMainSync { activity.findViewById<Button>(R.id.automation_test).performClick(); activity.recreate() }
            val next = inst.waitForMonitorWithTimeout(monitor,5000) as? AgentActivity
            assertNotNull("Activity recreated",next)
            inst.waitForIdleSync()
            inst.runOnMainSync {
                assertNotNull(next!!.findViewById<Button>(R.id.test_increment_button))
                assertEquals("IDLE",next.findViewById<android.widget.TextView>(R.id.test_scenario_status).text.toString())
                assertEquals("0",next.findViewById<android.widget.TextView>(R.id.test_counter_value).text.toString())
                next.finish()
            }
        } finally { inst.removeMonitor(monitor) }
    }
}

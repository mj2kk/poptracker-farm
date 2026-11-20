package cloud.poptracker.farm

import android.content.Intent
import android.os.SystemClock
import android.provider.Settings
import android.view.View
import android.widget.Button
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

// Run after the operator has enabled the service in Android settings.
@RunWith(AndroidJUnit4::class)
class EnabledServiceTest {
    @Test fun rejectsWrongScreenMissingButtonAndForeignWindow() {
        val inst = InstrumentationRegistry.getInstrumentation()
        val app = inst.targetContext
        val launch = Intent(app,AgentActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        val activity = inst.startActivitySync(launch) as AgentActivity
        fun expect(code: String) {
            val e = runCatching { runOwnUiScenario() }.exceptionOrNull()
            assertEquals(code,(e as? TaskProblem)?.code)
        }
        try {
            val deadline = SystemClock.elapsedRealtime() + 180_000
            while (OwnTestService.connected == null && SystemClock.elapsedRealtime() < deadline) Thread.sleep(100)
            assertNotNull("Enable the service manually first",OwnTestService.connected)
            // A manually enabled service can bind while Settings still owns the window.
            inst.runOnMainSync { app.startActivity(Intent(app,AgentActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)) }
            while (!activity.hasWindowFocus() && SystemClock.elapsedRealtime() < deadline) Thread.sleep(100)
            Thread.sleep(400)
            expect("TEST_SCREEN_NOT_OPEN")
            inst.runOnMainSync {
                activity.findViewById<Button>(R.id.automation_test).performClick()
                activity.findViewById<Button>(R.id.test_increment_button).visibility = View.GONE
            }
            inst.waitForIdleSync()
            expect("NODE_NOT_FOUND")
            inst.runOnMainSync { app.startActivity(Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
            val until = SystemClock.elapsedRealtime() + 4000
            while (activity.hasWindowFocus() && SystemClock.elapsedRealtime() < until) Thread.sleep(100)
            Thread.sleep(500)
            expect("TARGET_PACKAGE_MISMATCH")
        } finally {
            inst.runOnMainSync { activity.finish() }
        }
    }
}

package cloud.poptracker.farm

import android.accessibilityservice.AccessibilityService
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo
import java.util.concurrent.ExecutionException
import java.util.concurrent.FutureTask
import java.util.concurrent.TimeUnit
import java.util.concurrent.TimeoutException

internal fun requireOwnPackage(owner: CharSequence?, expected: String) {
    if (owner?.toString() != expected) throw UiFailure("TARGET_PACKAGE_MISMATCH")
}

class OwnTestService : AccessibilityService() {
    companion object {
        @Volatile internal var connected: OwnTestService? = null
            private set
    }
    @Volatile internal var interruption = 0
        private set

    override fun onServiceConnected() { super.onServiceConnected(); connected = this }
    override fun onAccessibilityEvent(event: AccessibilityEvent?) { /* No event content is recorded. */ }
    override fun onInterrupt() { interruption++ }
    override fun onUnbind(intent: android.content.Intent?): Boolean {
        if (connected === this) connected = null
        return super.onUnbind(intent)
    }
    override fun onDestroy() {
        if (connected === this) connected = null
        super.onDestroy()
    }
}

@Suppress("DEPRECATION")
internal class OwnTestSurface : TestSurface {
    private val main = Handler(Looper.getMainLooper())
    private val owner = BuildConfig.APPLICATION_ID
    private var service: OwnTestService? = null
    private var screen: AutomationTestScreen? = null
    private var generation = -1
    private var interruption = -1
    private var started = false

    override fun permission() {
        service = OwnTestService.connected ?: throw UiFailure("ACCESSIBILITY_DISABLED")
        interruption = service!!.interruption
    }

    private fun checkedRoot(): AccessibilityNodeInfo? {
        val s = service
        if (s == null || OwnTestService.connected !== s) throw UiFailure("ACCESSIBILITY_DISABLED")
        if (s.interruption != interruption) throw UiFailure("CANCELLED")
        val root = s.rootInActiveWindow ?: return null
        try { requireOwnPackage(root.packageName,owner) }
        catch (e: UiFailure) { root.recycle(); throw e }
        return root
    }

    override fun screen() {
        checkedRoot()?.recycle()
        val target = AutomationTestScreen.open ?: throw UiFailure("TEST_SCREEN_NOT_OPEN")
        if (!target.focused) throw UiFailure("TEST_SCREEN_NOT_OPEN")
        screen = target
        generation = target.generation
    }

    private fun checkSession() {
        val s = screen ?: throw UiFailure("TEST_SCREEN_NOT_OPEN")
        if (AutomationTestScreen.open !== s || !s.focused || s.generation != generation) throw UiFailure("CANCELLED")
    }

    override fun guard() {
        checkedRoot()?.recycle()
        checkSession()
    }

    private fun onMain(action: () -> Unit) {
        val task = FutureTask { action() }
        main.post(task)
        try { task.get(2,TimeUnit.SECONDS) }
        catch (e: TimeoutException) { task.cancel(false); throw UiFailure("STEP_TIMEOUT") }
        catch (e: ExecutionException) { throw (e.cause as? UiFailure ?: UiFailure("INTERNAL_ERROR")) }
    }

    override fun reset() {
        onMain {
            checkSession()
            if (OwnTestService.connected !== service) throw UiFailure("ACCESSIBILITY_DISABLED")
            screen!!.begin()
            started = true
        }
    }

    fun finish(state: String) {
        val target = screen ?: AutomationTestScreen.open ?: return
        // Do not modify a different screen or overwrite a user's later reset.
        onMain {
            if (AutomationTestScreen.open === target && (screen == null || target.generation == generation) &&
                (started || state == "FAILED")) target.finish(state)
        }
    }

    private fun <T> withNode(id: String, action: (AccessibilityNodeInfo) -> T): T? {
        val root = checkedRoot() ?: return null
        try {
            checkSession()
            val fullId = "$owner:id/$id"
            val matches = root.findAccessibilityNodeInfosByViewId(fullId)
            try {
                for (n in matches) {
                    requireOwnPackage(n.packageName,owner)
                    if (n.windowId != root.windowId || n.viewIdResourceName != fullId || !n.isVisibleToUser) continue
                    if (!n.refresh()) continue
                    requireOwnPackage(n.packageName,owner)
                    if (!n.isVisibleToUser || n.viewIdResourceName != fullId) continue
                    val active = checkedRoot() ?: return null
                    try {
                        checkSession()
                        if (active.windowId != n.windowId) throw UiFailure("CANCELLED")
                        return action(n)
                    } finally { active.recycle() }
                }
            } finally { matches.forEach { it.recycle() } }
            return null
        } finally { root.recycle() }
    }

    override fun counter() = withNode("test_counter_value") { it.text?.toString() }
    override fun increment() = withNode("test_increment_button") {
        it.isClickable && it.performAction(AccessibilityNodeInfo.ACTION_CLICK)
    }
    override fun scrollList() = withNode("test_list") {
        it.isScrollable && it.performAction(AccessibilityNodeInfo.ACTION_SCROLL_FORWARD)
    }
    override fun hasItem25() = withNode("test_list") { list ->
        val matches = list.findAccessibilityNodeInfosByText("Test Item 25")
        try {
            matches.any {
                requireOwnPackage(it.packageName,owner)
                it.windowId == list.windowId && it.isVisibleToUser && it.text?.toString() == "Test Item 25"
            }
        } finally { matches.forEach { it.recycle() } }
    }
}

internal fun runOwnUiScenario(): org.json.JSONObject {
    val surface = OwnTestSurface()
    var state = "FAILED"
    try {
        val result = UiScenario(surface,SystemClock::elapsedRealtime,Thread::sleep).run()
        state = "PASSED"
        return result
    } finally { surface.finish(state) }
}

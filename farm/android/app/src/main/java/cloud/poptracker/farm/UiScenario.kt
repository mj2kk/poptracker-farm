package cloud.poptracker.farm

import org.json.JSONArray
import org.json.JSONObject

internal const val UI_SCENARIO = "OWN_APP_BUTTON_AND_SCROLL_V1"
internal class UiFailure(val code: String) : Exception(code)

// Only the operations of this fixed test are expressible at this boundary.
internal interface TestSurface {
    fun permission()
    fun screen()
    fun guard()
    fun reset()
    fun counter(): String?
    fun increment(): Boolean?
    fun hasItem25(): Boolean?
    fun scrollList(): Boolean?
}

internal class UiScenario(private val surface: TestSurface, private val clock: () -> Long,
                          private val wait: (Long) -> Unit) {
    private val steps = JSONArray()
    private var step = "permission_check"
    private var stepStart = 0L
    private var start = 0L

    private fun budget() {
        if (clock() - start >= 30_000) throw UiFailure("SCENARIO_TIMEOUT")
    }

    private fun record(status: String, observed: String? = null) {
        steps.put(JSONObject().put("name",step).put("status",status)
            .put("duration_ms",clock() - stepStart).put("observed_value",observed))
    }

    private fun check(name: String, observed: String? = null, action: () -> Unit) {
        step = name
        stepStart = clock()
        budget()
        action()
        budget()
        if (clock() - stepStart >= 5000) throw UiFailure("STEP_TIMEOUT")
        record("PASSED",observed)
    }

    private fun await(interval: Long = 100, probe: () -> Boolean?) {
        while (true) {
            budget()
            surface.guard()
            val found = probe()
            if (found == true) return
            if (clock() - stepStart >= 4900) throw UiFailure(if (found == null) "NODE_NOT_FOUND" else "STEP_TIMEOUT")
            wait(interval)
        }
    }

    private fun report(status: String, code: String?) = JSONObject()
        .put("schema_version",1).put("scenario",UI_SCENARIO).put("status",status)
        .put("steps",steps).put("duration_ms",clock() - start).put("error_code",code ?: JSONObject.NULL)

    fun run(): JSONObject {
        start = clock()
        try {
            check("permission_check") { surface.permission() }
            check("screen_check") { surface.screen() }
            check("reset") { surface.guard(); surface.reset() }
            check("counter_zero","0") { await { surface.counter()?.let { it == "0" } } }
            check("button_click") {
                await {
                    val clicked = surface.increment()
                    if (clicked == false) throw UiFailure("ACTION_FAILED")
                    clicked
                }
            }
            check("counter_verify","1") { await { surface.counter()?.let { it == "1" } } }
            check("scroll_find_item","Test Item 25") {
                var scrolled = false
                await(280) {
                    val visible = surface.hasItem25()
                    if (visible == null) return@await null
                    if (scrolled && visible) return@await true
                    val moved = surface.scrollList() ?: return@await null
                    if (!moved) throw UiFailure("NODE_NOT_FOUND")
                    scrolled = true
                    false
                }
            }
            surface.guard()
            return report("PASSED",null)
        } catch (e: Exception) {
            val code = (e as? UiFailure)?.code ?: "INTERNAL_ERROR"
            // A final guard may fail after the last observation, during a window transition.
            if (steps.length() == 7) steps.remove(6)
            record("FAILED")
            throw TaskProblem(code, "Failed at " + step, report("FAILED",code))
        }
    }
}

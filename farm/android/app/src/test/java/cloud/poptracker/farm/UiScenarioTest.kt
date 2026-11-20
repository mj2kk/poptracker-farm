package cloud.poptracker.farm

import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class UiScenarioTest {
    private open class Surface : TestSurface {
        var count = 9
        var clicks = 0
        var scrolls = 0
        var reads = 0
        override fun permission() {}
        override fun screen() {}
        override fun guard() {}
        override fun reset() { count = 0 }
        override fun counter(): String? { reads++; return count.toString() }
        override fun increment(): Boolean? { count++; clicks++; return true }
        override fun hasItem25(): Boolean? = scrolls >= 2
        override fun scrollList(): Boolean? { scrolls++; return true }
    }
    private fun run(s: TestSurface, pause: Long = 0): JSONObject {
        var time = 0L
        return UiScenario(s,{time},{time += it + pause}).run()
    }
    private fun failure(s: TestSurface, pause: Long = 0) =
        runCatching { run(s,pause) }.exceptionOrNull() as TaskProblem

    @Test fun verifiesCounterAndScrollsWithOneClick() {
        val s = Surface()
        val r = run(s)
        assertEquals("PASSED",r.getString("status"))
        assertEquals("Test Item 25",r.getJSONArray("steps").getJSONObject(6).getString("observed_value"))
        assertEquals(1,s.clicks)
        assertEquals(1,s.count)
        assertTrue(s.scrolls > 0)
    }
    @Test fun missingNodeStepAndTotalDeadlinesHaveDistinctDiagnostics() {
        val missing = object : Surface() { override fun increment(): Boolean? = null }
        val problem = failure(missing)
        assertEquals("NODE_NOT_FOUND",problem.code)
        assertEquals("button_click",problem.diagnostic!!.getJSONArray("steps").getJSONObject(4).getString("name"))
        val stale = object : Surface() { override fun counter() = "8" }
        assertEquals("STEP_TIMEOUT",failure(stale).code)
        assertEquals("SCENARIO_TIMEOUT",failure(stale,30_001).code)
        assertEquals(0,stale.clicks)
    }
    @Test fun ownerChangeCancellationAndDisabledPermissionAreFailClosed() {
        requireOwnPackage("ours","ours")
        val foreign = object : Surface() { override fun guard() = requireOwnPackage("other","ours") }
        assertEquals("TARGET_PACKAGE_MISMATCH",failure(foreign).code)
        assertEquals(0,foreign.reads)
        assertEquals(0,foreign.clicks)
        val disabled = object : Surface() { override fun permission(): Unit = throw UiFailure("ACCESSIBILITY_DISABLED") }
        assertEquals("ACCESSIBILITY_DISABLED",failure(disabled).code)
        val closed = object : Surface() { override fun screen(): Unit = throw UiFailure("TEST_SCREEN_NOT_OPEN") }
        assertEquals("TEST_SCREEN_NOT_OPEN",failure(closed).code)
        val stopped = object : Surface() { override fun guard() { if (clicks == 1) throw UiFailure("CANCELLED") } }
        assertEquals("CANCELLED",failure(stopped).code)
        assertEquals(1,stopped.clicks)
    }
    @Test fun rejectedActionAndUnrecognizedParametersNeverRunExtraActions() {
        val s = object : Surface() { override fun increment() = false }
        assertEquals("ACTION_FAILED",failure(s).code)
        for (p in listOf(JSONObject().put("scenario","OTHER"),JSONObject().put("scenario",UI_SCENARIO).put("x",2))) {
            val job = JSONObject().put("schema_version",1).put("type","UI_TEST_SCENARIO").put("payload",p)
            val e = runCatching { executeTask(job,{error("device")},{"time"},{0L},{},{error("UI invoked")}) }.exceptionOrNull()
            assertEquals("INVALID_PAYLOAD",(e as TaskProblem).code)
        }
    }
    @Test fun expiredDeliveryPreservesTheUiReportForReconciliation() {
        val surface = Surface()
        var disk: String? = null
        val job = JSONObject().put("task_id","00000000-0000-4000-8000-000000000011")
            .put("attempt_id","00000000-0000-4000-8000-000000000012").put("lease_token","a".repeat(64))
            .put("type","UI_TEST_SCENARIO").put("payload",JSONObject().put("scenario",UI_SCENARIO)).put("schema_version",1)
        val engine = TaskEngine({disk?.let(::JSONObject)},{disk=it.toString()},{"2026-11-19T10:00:00Z"}) { run(surface) }
        engine.step({job},{_,_->error("send")},{true})
        val prepared = JSONObject(disk!!).getJSONObject("pending").getJSONObject("body").getJSONObject("result").toString()
        engine.step({error("claim")},{_,_->throw ApiFailure(409,"lease_expired")},{true})
        val journal = JSONObject(disk!!)
        assertFalse(journal.has("pending"))
        assertEquals(prepared,journal.getJSONObject("rejected").getJSONObject("result").toString())
        assertEquals("lease_expired",journal.getJSONObject("rejected").getString("reason"))
        assertEquals(1,surface.clicks)
    }

    @Test fun retransmissionDoesNotResetOrClickAgain() {
        val s = Surface()
        var disk: String? = null
        val job = JSONObject().put("task_id","00000000-0000-4000-8000-000000000001")
            .put("attempt_id","00000000-0000-4000-8000-000000000002").put("lease_token","a".repeat(64))
            .put("type","UI_TEST_SCENARIO").put("payload",JSONObject().put("scenario",UI_SCENARIO)).put("schema_version",1)
        fun engine() = TaskEngine({ disk?.let(::JSONObject) },{disk=it.toString()},{"2026-11-19T10:00:00Z"}) { run(s) }
        engine().step({job},{_,_->error("send")},{true})
        val stored = disk
        assertTrue(runCatching { engine().step({error("claim")},{_,_->throw java.io.IOException()},{true}) }.isFailure)
        assertEquals(stored,disk)
        engine().step({error("claim")},{id,b -> JSONObject().put("task_id",id).put("attempt_id",b.getString("attempt_id"))
            .put("outcome","succeeded").put("received_at","2026-11-19T10:00:01Z")},{true})
        assertEquals(1,s.clicks)
    }
}

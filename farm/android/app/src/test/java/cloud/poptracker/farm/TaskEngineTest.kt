package cloud.poptracker.farm

import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.net.SocketTimeoutException

class TaskEngineTest {
    private val time = "2026-11-19T15:00:00Z"
    private fun job(type: String = "PING", payload: JSONObject = JSONObject()) = JSONObject()
        .put("task_id","00000000-0000-4000-8000-000000000001")
        .put("attempt_id","00000000-0000-4000-8000-000000000002").put("lease_token","f".repeat(64))
        .put("schema_version",1).put("type",type).put("payload",payload).put("lease_expires_at",time)
    private var disk: String? = null
    private var executions = 0
    private fun engine() = TaskEngine({disk?.let(::JSONObject)}, {disk = it.toString()}, {time}) {
        executions++
        JSONObject().put("pong",true).put("executed_at",time)
    }
    private fun receipt(id: String, b: JSONObject) = JSONObject().put("task_id",id).put("attempt_id",b.getString("attempt_id"))
        .put("received_at",time).put("outcome",b.getString("outcome"))

    @Test fun allowlistAndPayloadValidation() {
        var elapsed = 50L
        val status = JSONObject().put("model","CPH2197").put("api_level",33)
        fun run(j: JSONObject) = executeTask(j,{status},{time},{elapsed},{elapsed += it})
        assertTrue(run(job()).getBoolean("pong"))
        assertEquals(33,run(job("GET_DEVICE_STATUS")).getInt("api_level"))
        val sleep = run(job("EXECUTE_TEST_TASK",JSONObject().put("duration_ms",1000)))
        assertEquals(1000,sleep.getInt("elapsed_ms"))
        assertTrue(sleep.getBoolean("completed"))
        for (j in listOf(job("SHELL"),job().put("schema_version",2))) {
            assertEquals("UNSUPPORTED_TASK", (runCatching { run(j) }.exceptionOrNull() as TaskProblem).code)
        }
        for (j in listOf(job("PING",JSONObject().put("url","x")),job("EXECUTE_TEST_TASK",JSONObject().put("duration_ms",5001)),
            job("EXECUTE_TEST_TASK",JSONObject().put("duration_ms","5")))) {
            assertEquals("INVALID_PAYLOAD",(runCatching { run(j) }.exceptionOrNull() as TaskProblem).code)
        }
    }

    @Test fun lostAcknowledgementReusesSavedBodyAcrossProcessRestart() {
        engine().step({job()}, {_,_->error("send during claim")}, {true})
        var original = ""
        val offline = runCatching {
            engine().step({error("second claim")},{_,b -> original=b.toString(); throw SocketTimeoutException()}, {true})
        }
        assertTrue(offline.exceptionOrNull() is SocketTimeoutException)
        engine().step({error("second claim")},{id,b -> assertEquals(original,b.toString()); receipt(id,b)}, {true})
        assertEquals(1,executions)
        assertFalse(JSONObject(disk!!).has("pending"))
        assertEquals("succeeded",JSONObject(disk!!).getJSONObject("last").getString("outcome"))
    }

    @Test fun uncertainExecutionIsNeverRepeated() {
        disk = JSONObject().put("pending",JSONObject().put("job",job())).toString()
        engine().step({error("claim")},{_,_->error("send")},{true})
        engine().step({error("claim")},{id,b ->
            assertEquals("EXECUTION_UNKNOWN",b.getJSONObject("error").getString("code")); receipt(id,b)
        },{true})
        assertEquals(0,executions)
    }

    @Test fun transientFailuresAndRevocationKeepTheResult() {
        engine().step({job()},{_,_->error("send")},{true})
        val saved = disk
        for (e in listOf(ApiFailure(401),ApiFailure(503),SocketTimeoutException())) {
            val failure = runCatching { engine().step({error("claim")},{_,_->throw e},{true}) }
            assertSame(e,failure.exceptionOrNull())
            assertEquals(saved,disk)
        }
        engine().step({error("claim")},{_,_->throw ApiFailure(409,"lease_expired")},{true})
        assertEquals("lease_expired",JSONObject(disk!!).getJSONObject("rejected").getString("reason"))
        assertEquals(1,executions)
    }

    @Test fun queuedResultRetriesBeforeSpendingAnotherMinuteOfItsLease() {
        for (failures in 0..20) {
            assertTrue(taskPollDelay(SocketTimeoutException(),failures,true) in 2_500L..12_000L)
        }
        assertEquals(60_000L,taskPollDelay(ApiFailure(429),10,true))
    }

    @Test fun emptyPollBackoffAndInvalidReceipt() {
        engine().step({null},{_,_->error("send")},{true})
        assertNull(disk)
        assertEquals(12_000L,taskPollDelay(null,0))
        assertEquals(60_000L,taskPollDelay(ApiFailure(429),0))
        assertTrue(taskPollDelay(ApiFailure(500),10) in 30_000L..60_000L)
        engine().step({job()},{_,_->error("send")},{true})
        val saved = disk
        val failed = runCatching { engine().step({error("claim")},{_,_->JSONObject()},{true}) }
        assertTrue(failed.exceptionOrNull() is java.io.IOException)
        assertEquals(saved,disk)
    }

    @Test fun v2OfflineFiveMinutesAndRestartDeliverSameTimedBody() {
        var mono = 9000L
        fun instance() = TaskEngine({disk?.let(::JSONObject)}, {disk=it.toString()}, {time}, {mono}) {
            executions++
            mono += 600
            JSONObject().put("pong",true).put("executed_at",time)
        }
        val claim = job().put("result_protocol",2).put("start_budget_ms",20000).put("execution_budget_ms",120000)
        instance().step({ mono += 85; claim },{_,_->error("send")},{true})
        val saved = JSONObject(disk!!).getJSONObject("pending").getJSONObject("body")
        assertEquals(85,saved.getJSONObject("execution_timing").getInt("start_ms"))
        assertEquals(685,saved.getJSONObject("execution_timing").getInt("finish_ms"))
        for (seconds in listOf(130,300)) {
            mono += seconds*1000L
            assertTrue(runCatching { instance().step({error("claim")},{_,b->
                assertEquals(saved.toString(),b.toString())
                throw SocketTimeoutException()
            },{true}) }.exceptionOrNull() is SocketTimeoutException)
        }
        instance().step({error("claim")},{id,b->receipt(id,b)},{true})
        assertEquals(1,executions)
        assertFalse(JSONObject(disk!!).has("pending"))
    }

    @Test fun v2EnforcesStartAndExecutionDeadlines() {
        for (claimDelay in listOf(1L,20001L)) {
            disk=null
            var mono=0L
            var clicks=0
            val e=TaskEngine({disk?.let(::JSONObject)},{disk=it.toString()},{time},{mono}) {
                clicks++; mono+=120001L; JSONObject()
            }
            e.step({ mono+=claimDelay; job().put("result_protocol",2).put("start_budget_ms",20000).put("execution_budget_ms",120000) },
                {_,_->error("send")},{true})
            val body=JSONObject(disk!!).getJSONObject("pending").getJSONObject("body")
            assertEquals(if (claimDelay==1L) 1 else 0,clicks)
            assertEquals("EXECUTION_FAILED",body.getJSONObject("error").getString("code"))
            assertTrue(body.isNull("execution_timing"))
        }
    }
}

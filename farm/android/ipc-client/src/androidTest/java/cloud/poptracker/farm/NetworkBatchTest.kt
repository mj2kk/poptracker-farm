package cloud.poptracker.farm

import android.net.Uri
import android.os.Process
import android.os.SystemClock
import androidx.test.platform.app.InstrumentationRegistry
import cloud.poptracker.farm.ipc.TestBatchContract as C
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.io.File
import java.io.IOException

class NetworkBatchTest {
    private val instrumentation = InstrumentationRegistry.getInstrumentation()
    private val context = instrumentation.targetContext
    private val vault = Vault(context)
    private val batches = TestBatchClient(context)
    private val origin = DEFAULT_ORIGIN + "/step05"
    private fun credentials() = requireNotNull(vault.read())
    private fun workCount() = context.contentResolver.call(Uri.parse("content://${C.AUTHORITY}"),"stats_v1",null,null)!!.getInt("work_count")
    private fun claim(): JSONObject? {
        val c = credentials()
        return requestJson(origin,"tasks/claim",JSONObject().put("device_id",c.id).put("agent_version",BuildConfig.VERSION_NAME)
            .put("result_protocol",2).put("capabilities",JSONArray().put(C.TASK)),c.token)
    }
    private fun send(id: String, body: JSONObject): JSONObject = request(origin,"tasks/$id/result",body,credentials().token)
    private fun engine(prefix: String, execute: (JSONObject)->JSONObject) = TaskEngine(
        { vault.readSealed(prefix) }, { vault.writeSealed(prefix,it) }, ::utcNow,SystemClock::elapsedRealtime,execute=execute)
    private fun report(name: String, json: JSONObject) {
        File(context.filesDir,"$name.json").writeText(json.toString(2))
        instrumentation.sendStatus(0, android.os.Bundle().apply { putString("report",json.toString()) })
    }
    @Test fun enrollControlledClient() {
        val code = requireNotNull(InstrumentationRegistry.getArguments().getString("enrollmentCode"))
        val response=request(origin,"enroll",deviceFields().put("code",code))
        vault.save(Credentials(DEFAULT_ORIGIN,response.getString("device_id"),response.getString("token")))
        report("enrollment",JSONObject().put("device_id",response.getString("device_id")).put("pid",Process.myPid()))
    }
    @Test fun tenJobsTraverseHttpsEngineContentProviderAndDatabaseReceipt() {
        val prefix="controlled_ten_"
        assertNull(vault.readSealed(prefix))
        val receipts=JSONArray();val seen=mutableSetOf<String>();val before=workCount()
        val engine=engine(prefix,batches::execute)
        repeat(10) {
            engine.step(::claim,::send) { true }
            val pending=requireNotNull(vault.readSealed(prefix)?.optJSONObject("pending"))
            assertEquals("succeeded",pending.getJSONObject("body").getString("outcome"))
            engine.step({ error("A pending result should be delivered first") },{ id,body ->
                val ack=send(id,body)
                assertEquals("succeeded",ack.getString("outcome"));assertFalse(ack.getBoolean("replayed"))
                val actual=body.getJSONObject("result");val target=actual.getJSONArray("observations").getJSONObject(0)
                assertTrue(actual.getBoolean("synthetic"));assertEquals(C.SOURCE,actual.getString("source"))
                assertTrue(seen.add(target.getString("uid")))
                receipts.put(JSONObject().put("task_id",id).put("status",ack.getString("outcome"))
                    .put("uid",target.getString("uid")).put("vid",target.getString("vid")).put("region",target.getString("region"))
                    .put("processing_ms",body.getJSONObject("execution_timing").getLong("finish_ms")-body.getJSONObject("execution_timing").getLong("start_ms"))
                    .put("received_at",ack.getString("received_at")).put("error",JSONObject.NULL))
                ack
            }) { true }
            assertFalse(vault.readSealed(prefix)!!.has("pending"))
            Thread.sleep(1100)
        }
        assertEquals(10,seen.size);assertEquals(before+10,workCount())
        report("ten-network-jobs",JSONObject().put("source",C.SOURCE).put("jobs",receipts).put("executions",10))
    }
    @Test fun prepareLostAcknowledgementRecovery() {
        val prefix="controlled_recovery_";val engine=engine(prefix,batches::execute)
        check(vault.readSealed(prefix)?.has("pending") != true)
        vault.writeSealed(prefix, JSONObject())
        engine.step(::claim,::send) { true }
        assertEquals("succeeded",vault.readSealed(prefix)!!.getJSONObject("pending").getJSONObject("body").getString("outcome"))
        try {
            engine.step({ error("Unexpected claim") },{ id,body -> send(id,body);throw IOException("Simulated lost acknowledgement") }) { true }
            fail("Delivery fault was ignored")
        } catch (_: IOException) { }
        vault.writeSealed("controlled_recovery_count_",JSONObject().put("count",workCount()).put("pid",Process.myPid()))
        report("recovery-before-stop",JSONObject().put("pending_body",true).put("pid",Process.myPid()))
    }
    @Test fun deliverAfterClientProcessRestart() {
        val before=requireNotNull(vault.readSealed("controlled_recovery_count_"))
        assertNotEquals(before.getInt("pid"),Process.myPid())
        val countBeforeDelivery=workCount()
        var executions=0
        val engine=engine("controlled_recovery_") { executions++;batches.execute(it) }
        var replayed=false
        engine.step({ error("Must deliver durable pending body") },{ id,body ->
            val ack=send(id,body);replayed=ack.getBoolean("replayed");ack
        }) { true }
        assertTrue(replayed);assertEquals(0,executions);assertEquals(countBeforeDelivery,workCount())
        report("recovery-after-stop",JSONObject().put("replayed",replayed).put("provider_executions_during_recovery",executions))
    }
    @Test fun terminateClientAfterProviderReplyBeforeSavingResult() {
        val prefix="controlled_unknown_";assertNull(vault.readSealed(prefix))
        val engine=engine(prefix) { job ->
            val result=batches.execute(job)
            vault.writeSealed("controlled_unknown_count_",JSONObject().put("count",workCount()).put("pid",Process.myPid()))
            Process.killProcess(Process.myPid())
            result
        }
        engine.step(::claim,::send) { true }
        fail("Controlled client process was expected to terminate")
    }
    @Test fun recoverUnknownExecutionWithoutCallingProviderAgain() {
        val prefix="controlled_unknown_";val before=requireNotNull(vault.readSealed("controlled_unknown_count_"))
        assertNotEquals(before.getInt("pid"),Process.myPid())
        assertFalse(vault.readSealed(prefix)!!.getJSONObject("pending").has("body"))
        var executions=0
        val engine=engine(prefix) { executions++;batches.execute(it) }
        engine.step({ error("Unexpected claim") },::send) { true }
        engine.step({ error("Unexpected claim") },::send) { true }
        val last=vault.readSealed(prefix)!!.getJSONObject("last")
        assertEquals("failed",last.getString("outcome"));assertEquals("EXECUTION_UNKNOWN",last.getJSONObject("detail").getString("code"))
        assertEquals(0,executions);assertEquals(before.getInt("count"),workCount())
        report("unknown-after-stop",JSONObject().put("outcome","failed").put("error","EXECUTION_UNKNOWN").put("provider_executions_during_recovery",executions))
    }
}

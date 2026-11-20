package cloud.poptracker.farm

import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.io.IOException

class CollectionTest {
    private val stamp = "2026-11-19T15:00:00Z"
    private fun job(count: Any = 10) = JSONObject()
        .put("task_id","10000000-0000-4000-8000-000000000001")
        .put("attempt_id","20000000-0000-4000-8000-000000000001")
        .put("device_id","30000000-0000-4000-8000-000000000001")
        .put("lease_token","a".repeat(64)).put("schema_version",1)
        .put("type",COLLECTION_TYPE).put("result_protocol",2)
        .put("start_budget_ms",20000).put("execution_budget_ms",120000)
        .put("payload",JSONObject().put("dataset","synthetic_videos").put("record_count",count))
    private fun generate(j: JSONObject) = executeTask(j,{ error("device status") },{stamp},{0L},{ error("sleep") })

    @Test fun generatesStableIdsWithinTaskAndRespectsBounds() {
        for (n in listOf(10,100)) {
            val batch = generate(job(n))
            assertEquals(job().getString("device_id"),batch.getString("device_id"))
            val records = batch.getJSONArray("records")
            assertEquals(n,records.length())
            val ids = (0 until n).map { records.getJSONObject(it).getString("external_id") }
            assertEquals(n,ids.toSet().size)
            assertEquals("2026-11-19T14:59:00Z",records.getJSONObject(0).getString("published_at"))
            assertEquals(stamp,records.getJSONObject(0).getString("collected_at"))
            assertTrue(records.getJSONObject(0).getInt("likes") <= records.getJSONObject(0).getInt("views"))
            val again = generate(job(n).put("attempt_id", "40000000-0000-4000-8000-000000000001"))
            assertEquals(batch.getString("batch_id"),again.getString("batch_id"))
            assertEquals(records.toString(),again.getJSONArray("records").toString())
        }
        for (count in listOf(9,101,"10")) {
            assertTrue(runCatching { generate(job(count)) }.exceptionOrNull() is TaskProblem)
        }
    }

    @Test fun restartResendsTheSavedBatchUntilItsOwnStoredReceipt() {
        var disk: String? = null
        var executions = 0
        fun engine() = TaskEngine({ disk?.let(::JSONObject) },{ disk=it.toString() },{stamp},{0L}) {
            executions++
            generate(it)
        }
        engine().step({job()},{_,_->error("send")},{true})
        val snapshot = disk
        val pending = JSONObject(snapshot!!).getJSONObject("pending")
        val original = pending.getJSONObject("body")
        val batch = original.getJSONObject("result")
        val ack = JSONObject().put("task_id",batch.getString("task_id")).put("attempt_id",batch.getString("attempt_id"))
            .put("received_at",stamp).put("outcome","succeeded")
        fun send(reply: () -> JSONObject) = engine().step({error("second claim")},{_,b ->
            assertEquals(original.toString(),b.toString())
            reply()
        },{true})
        for (reply in listOf<() -> JSONObject>({throw IOException("offline")}, {throw IOException("response lost")}, {ack},
            {ack.put("result",JSONObject().put("status","stored").put("record_count",10).put("batch_id","other"))})) {
            assertTrue(runCatching { send(reply) }.exceptionOrNull() is IOException)
            assertEquals(snapshot,disk)
        }
        send { ack.put("result",JSONObject().put("batch_id",batch.getString("batch_id")).put("record_count",10).put("status","stored")) }
        assertFalse(JSONObject(disk!!).has("pending"))
        assertEquals(1,executions)
    }

    @Test fun permanentConflictPreservesTheFullBatchBeforeReleasingTheQueue() {
        var disk: String? = null
        var archived: JSONObject? = null
        var storageFailure = true
        val engine = TaskEngine({disk?.let(::JSONObject)},{disk=it.toString()},{stamp},{0L},{ _, entry ->
            if (storageFailure) throw IOException("archive write failed")
            archived=entry
        },::generate)
        engine.step({job()},{_,_->error("send")},{true})
        val original=JSONObject(disk!!).getJSONObject("pending").getJSONObject("body").toString()
        fun conflict() = engine.step({error("claim")},{_,_->throw ApiFailure(409,"result_conflict")},{true})
        assertTrue(runCatching { conflict() }.exceptionOrNull() is IOException)
        assertTrue(JSONObject(disk!!).has("pending"))
        storageFailure=false
        conflict()
        assertEquals(original,archived!!.getJSONObject("body").toString())
        assertFalse(JSONObject(disk!!).has("pending"))
    }
}

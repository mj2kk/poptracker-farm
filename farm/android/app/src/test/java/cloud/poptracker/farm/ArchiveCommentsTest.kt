package cloud.poptracker.farm

import java.io.IOException
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class ArchiveCommentsTest {
    private val time = "2026-11-20T10:00:00Z"
    private fun row(id: Any, text: Any) = JSONObject().put("id", id).put("text", text)
    private fun payload(vararg rows: JSONObject): JSONObject =
        JSONObject().put("video_id", "7").put("comments", JSONArray(rows.toList()))
    private fun job(payload: JSONObject): JSONObject = JSONObject().put("type", ARCHIVE_COMMENTS)
        .put("task_id", "00000000-0000-4000-8000-000000000001")
        .put("attempt_id", "00000000-0000-4000-8000-000000000002").put("lease_token", "a".repeat(64))
        .put("result_protocol", 2).put("schema_version", 1).put("payload", payload)
        .put("start_budget_ms", 20000).put("execution_budget_ms", 120000)
    private fun execute(j: JSONObject) = executeTask(j, { error("status") }, { time }, { 0L }, { error("sleep") })
    private fun rejected(p: JSONObject) {
        assertEquals("INVALID_PAYLOAD", assertThrows(TaskProblem::class.java) { execute(job(p)) }.code)
    }

    @Test fun hashesExactUtf8InSourceOrder() {
        val batch = payload(row("9", "  Салют 🚀\n"), row("002", "é"), row("4", "e\u0301"), row("15", " \t "))
        val result = execute(job(batch))
        assertEquals("7", result.getString("video_id"))
        val records = result.getJSONArray("comments")
        assertEquals(listOf("9","002","4","15"), (0 until 4).map { records.getJSONObject(it).getString("id") })
        assertEquals(listOf(
            "a0062098b88bedc6e864066bc841ff06c8f1595bd09e9968107817f770acf3ac",
            "4a99557e4033c3539de2eb65472017cad5f9557f7a0625a09f1c3f6e2ba69c4c",
            "bf12767b0f2a56b2190075bae8169f656e3ce8d6357d4aff184bc6c7ea48f9f6",
            "da7f1c8ac9f025a27f301d632197839ebd621d380372f5eb3ef8b4d2074daef9"
        ), (0 until 4).map { records.getJSONObject(it).getString("sha256") })
        assertFalse(result.toString().contains("text"))
    }

    @Test fun validatesIdsFieldsCountsAndUtf8Bounds() {
        rejected(payload())
        rejected(payload(row("1", ""), row("2", "a")))
        rejected(payload(row("1", "\ud800")))
        rejected(payload(row("1", "a"), row("1", "b")))
        rejected(payload(row(1, "a")))
        rejected(payload(row("1", 2)))
        rejected(payload(row("1", "a").put("author", "x")))
        rejected(payload(row("1", "a")).put("private", true))
        for (id in listOf("", "1e2", "１２", "0".repeat(33))) {
            rejected(payload(row(id, "a")))
            rejected(payload(row("1", "a")).put("video_id", id))
        }
        val twenty = payload(*(1..20).map { row(it.toString(), "x") }.toTypedArray())
        assertEquals(20, execute(job(twenty)).getJSONArray("comments").length())
        twenty.getJSONArray("comments").put(row("21", "x"))
        rejected(twenty)

        val overhead = """{"video_id":"7","comments":[{"id":"1","text":""}]}""".length
        // Includes the six-byte PHP escape for U+2028, the two-byte newline escape,
        // and a slash which stays literal even when Android's JSONObject escapes it.
        val edge = "\u2028\n/" + "a".repeat(32768 - overhead - 9)
        assertEquals(1, execute(job(payload(row("1", edge)))).getJSONArray("comments").length())
        rejected(payload(row("1", edge + "x")))
        assertEquals("UNSUPPORTED_TASK", assertThrows(TaskProblem::class.java) {
            execute(job(payload(row("1", "a"))).put("result_protocol", 1))
        }.code)
    }

    @Test fun restartRedeliversOnlyHashesAndWaitsForMatchingReceipt() {
        val original = "archive-private-text"
        val claim = job(payload(row("1", original), row("2", "second comment")))
        var disk: String? = null
        var executions = 0
        fun engine() = TaskEngine({ disk?.let(::JSONObject) }, {
            disk = it.toString()
            assertFalse(disk!!.contains(original))
            assertFalse(taskSummary(it).contains(original))
        }, { time }, { 0L }) { executions++; execute(it) }
        engine().step({ claim }, { _, _ -> error("premature send") }, { true })
        val saved = JSONObject(disk!!).getJSONObject("pending").getJSONObject("body")
        val ack = JSONObject().put("task_id", claim.getString("task_id")).put("attempt_id", claim.getString("attempt_id"))
            .put("received_at", time).put("outcome", "succeeded")
        fun send(receipt: () -> JSONObject) = engine().step({ error("new claim") }, { _, body ->
            assertEquals(saved.toString(), body.toString())
            receipt()
        }, { true })
        val wrongResult = JSONObject(saved.getJSONObject("result").toString())
        wrongResult.getJSONArray("comments").getJSONObject(0).put("sha256", "0".repeat(64))
        for (reply in listOf<() -> JSONObject>({ throw IOException("offline") }, { ack }, { ack.put("result", wrongResult) })) {
            assertThrows(IOException::class.java) { send(reply) }
            assertNotNull(JSONObject(disk!!).optJSONObject("pending"))
        }
        send { ack.put("replayed", true).put("result", saved.getJSONObject("result")) }
        val final = JSONObject(disk!!)
        assertFalse(final.has("pending"))
        assertEquals(1, executions)
        assertEquals(2, final.getJSONObject("last").getJSONObject("detail").getInt("comment_count"))
        assertFalse(disk!!.contains("sha256"))
        assertTrue(taskSummary(final).contains("Проверено комментариев: 2"))
    }

    @Test fun permanentRejectionReleasesTheQueue() {
        var disk: String? = null
        val engine = TaskEngine({ disk?.let(::JSONObject) }, { disk = it.toString() }, { time }, { 0L }, execute = ::execute)
        engine.step({ job(payload(row("123", "private value"))) }, { _, _ -> error("send") }, { true })
        engine.step({ error("new claim") }, { _, _ -> throw ApiFailure(422) }, { true })
        val state = JSONObject(disk!!)
        assertFalse(state.has("pending"))
        assertEquals("HTTP 422", state.getJSONObject("rejected").getString("reason"))
        assertFalse(disk!!.contains("private value"))
    }
}

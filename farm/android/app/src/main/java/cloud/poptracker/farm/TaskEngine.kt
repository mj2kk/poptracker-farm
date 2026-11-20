package cloud.poptracker.farm

import org.json.JSONObject
import java.io.IOException

private val uuid = Regex("[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}")

internal class TaskProblem(val code: String, message: String, val diagnostic: JSONObject? = null) : Exception(message)

internal fun executeTask(job: JSONObject, device: () -> JSONObject, now: () -> String,
                         elapsed: () -> Long, sleep: (Long) -> Unit, uiScenario: () -> JSONObject = ::runOwnUiScenario): JSONObject {
    if (job.opt("schema_version") != 1) throw TaskProblem("UNSUPPORTED_TASK", "Unsupported schema version")
    val payload = job.optJSONObject("payload") ?: throw TaskProblem("INVALID_PAYLOAD", "Expected object")
    fun fields(vararg names: String) {
        if (payload.keys().asSequence().toSet() != names.toSet()) throw TaskProblem("INVALID_PAYLOAD", "Unexpected fields")
    }
    return when (job.opt("type")) {
        ARCHIVE_COMMENTS -> {
            if (job.opt("result_protocol") != 2) throw TaskProblem("UNSUPPORTED_TASK", "Task requires protocol 2")
            fields("video_id", "comments")
            archiveComments(payload)
        }
        COLLECTION_TYPE -> {
            fields("dataset","record_count")
            collectSyntheticVideos(job,payload,now())
        }
        "UI_TEST_SCENARIO" -> {
            fields("scenario")
            if (payload.opt("scenario") != UI_SCENARIO) throw TaskProblem("INVALID_PAYLOAD", "Unknown UI scenario")
            uiScenario()
        }
        "PING" -> { fields(); JSONObject().put("pong", true).put("executed_at", now()) }
        "GET_DEVICE_STATUS" -> { fields(); device() }
        "EXECUTE_TEST_TASK" -> {
            fields("duration_ms")
            val duration = payload.opt("duration_ms")
            if (duration !is Int || duration !in 0..5000) throw TaskProblem("INVALID_PAYLOAD", "Invalid duration")
            val start = elapsed()
            sleep(duration.toLong())
            JSONObject().put("requested_duration_ms", duration).put("elapsed_ms", elapsed() - start).put("completed", true)
        }
        else -> throw TaskProblem("UNSUPPORTED_TASK", "Unsupported type")
    }
}

// A single worker owns the journal. Each save durably replaces its previous snapshot.
internal class TaskEngine(
    private val load: () -> JSONObject?,
    private val save: (JSONObject) -> Unit,
    private val now: () -> String,
    private val elapsed: () -> Long = { System.nanoTime() / 1_000_000 },
    private val archive: (String, JSONObject) -> Unit = { _, _ -> throw IOException("Archive unavailable") },
    private val execute: (JSONObject) -> JSONObject
) {
    fun step(claim: () -> JSONObject?, send: (String, JSONObject) -> JSONObject, active: () -> Boolean) {
        val journal = load() ?: JSONObject()
        val pending = journal.optJSONObject("pending")
        if (pending != null) {
            val job = pending.getJSONObject("job")
            val body = pending.optJSONObject("body")
            if (body == null) {
                // The previous process might already have executed the operation.
                prepare(journal, job, null, TaskProblem("EXECUTION_UNKNOWN", "Previous execution outcome is unknown"))
                return
            }
            val batch = if (job.opt("type") == COLLECTION_TYPE) body.optJSONObject("result") else null
            val receipt = try {
                send(job.getString("task_id"), body)
            } catch (e: ApiFailure) {
                if (batch != null && e.status in setOf(403,404,409,413,422)) {
                    val cause = e.reason ?: "HTTP ${e.status}"
                    archive(job.getString("attempt_id"), JSONObject().put("body",body).put("job",job).put("reason",cause))
                    journal.put("last_error", job.getString("task_id") + " · пакет сохранён локально · " + cause)
                    journal.remove("pending")
                    save(journal)
                    return
                }
                val rejectedArchive = job.opt("type") == ARCHIVE_COMMENTS && e.status in setOf(403, 404, 413, 422)
                val closedAttempt = e.status == 409 && e.reason in setOf("lease_expired","result_conflict","delivery_expired",
                    "attempt_superseded","attempt_closed","execution_window")
                if (!rejectedArchive && !closedAttempt) throw e
                val reason = e.reason ?: "HTTP " + e.status
                journal.put("last_error", job.getString("task_id") + " · " + reason)
                journal.put("rejected", JSONObject().put("task_id", job.getString("task_id"))
                    .put("attempt_id", job.getString("attempt_id"))
                    .put("execution_timing",body.opt("execution_timing"))
                    .put("completed_at", body.getString("completed_at")).put("result",body.get("result"))
                    .put("error",body.get("error")).put("reason",reason))
                journal.remove("pending")
                save(journal)
                return
            }
            if (receipt.opt("task_id") != job.getString("task_id") || receipt.opt("attempt_id") != job.getString("attempt_id")
                || receipt.opt("outcome") != body.getString("outcome") || !receipt.has("received_at")) {
                throw IOException("Invalid receipt")
            }
            val stored = receipt.optJSONObject("result")
            if (batch != null && (stored?.opt("batch_id") != batch.getString("batch_id")
                    || stored?.opt("record_count") != batch.getJSONArray("records").length()
                    || stored.opt("status") != "stored")) throw IOException("Invalid batch receipt")
            val detail = when {
                body.getString("outcome") == "failed" -> body.getJSONObject("error")
                batch != null -> stored
                job.opt("type") == ARCHIVE_COMMENTS -> {
                    val result = body.getJSONObject("result")
                    if (!matchesArchiveReceipt(result, stored)) throw IOException("Invalid archived comment receipt")
                    JSONObject().put("comment_count", result.getJSONArray("comments").length())
                }
                else -> body.getJSONObject("result")
            }
            journal.put("last", JSONObject().put("task_id", job.getString("task_id")).put("type", job.getString("type"))
                .put("outcome", receipt.getString("outcome")).put("received_at", receipt.getString("received_at"))
                .put("detail", detail))
            journal.remove("pending")
            if (body.getString("outcome") == "failed") journal.put("last_error", body.getJSONObject("error").getString("code"))
            save(journal)
            return
        }
        val requestedAt = elapsed()
        val job = claim() ?: return
        require(uuid.matches(job.getString("task_id")) && uuid.matches(job.getString("attempt_id")) &&
            Regex("[a-f0-9]{64}").matches(job.getString("lease_token"))) { "Invalid lease envelope" }
        // Recovery only needs the lease. Comment text stays in memory during execution.
        val persistedJob = if (job.opt("type") == ARCHIVE_COMMENTS) {
            JSONObject(job, arrayOf("type", "task_id", "attempt_id", "lease_token", "result_protocol"))
        } else job
        journal.put("pending", JSONObject().put("job", persistedJob))
        save(journal)
        val v2 = job.optInt("result_protocol",1) == 2
        val startMs = elapsed() - requestedAt
        var timing: JSONObject? = null
        var problem: TaskProblem? = null
        var result = try {
            if (v2 && startMs !in 0L..job.getLong("start_budget_ms")) {
                throw TaskProblem("EXECUTION_FAILED", "Start deadline passed")
            }
            if (!active()) throw TaskProblem("EXECUTION_FAILED", "Activity stopped before execution")
            if (v2) timing = JSONObject().put("start_ms",startMs)
            execute(job)
        } catch (e: TaskProblem) { problem = e; e.diagnostic }
        catch (e: Exception) { problem = TaskProblem("EXECUTION_FAILED", "Local operation failed"); null }
        if (timing != null) {
            val finishMs = elapsed() - requestedAt
            if (finishMs > job.getLong("execution_budget_ms")) {
                result = null
                timing = null
                problem = TaskProblem("EXECUTION_FAILED","Execution deadline passed")
            } else {
                timing.put("finish_ms",finishMs)
            }
        }
        prepare(journal, job, result, problem, timing)
    }

    private fun prepare(journal: JSONObject, job: JSONObject, result: JSONObject?, problem: TaskProblem?, timing: JSONObject? = null) {
        val error = problem?.let { JSONObject().put("code", it.code).put("message", it.message) }
        val body = JSONObject().put("attempt_id",job.getString("attempt_id")).put("lease_token",job.getString("lease_token"))
            .put("outcome", if (error == null) "succeeded" else "failed").put("completed_at",now())
            .put("result",result ?: JSONObject.NULL).put("error",error ?: JSONObject.NULL)
        if (job.optInt("result_protocol",1) == 2) body.put("execution_timing",timing ?: JSONObject.NULL)
        journal.getJSONObject("pending").put("body",body)
        save(journal)
    }
}

internal fun taskSummary(journal: JSONObject?): String {
    val pending = journal?.optJSONObject("pending")
    val job = pending?.getJSONObject("job")
    val current = if (job == null) "Очередь свободна" else {
        val phase = if (pending.has("body")) "ожидает отправки" else "выполняется"
        if (job.opt("type") == ARCHIVE_COMMENTS) "Проверка сохранённых комментариев · " + phase
        else job.getString("type") + " · " + phase + "\n" + job.getString("task_id")
    }
    val last = journal?.optJSONObject("last")
    val summary = if (last == null) "Подтверждённых результатов пока нет"
    else if (last.opt("type") == ARCHIVE_COMMENTS) {
        if (last.opt("outcome") == "succeeded") "Проверено комментариев: " + last.getJSONObject("detail").getInt("comment_count")
        else "Проверка сохранённых комментариев завершилась ошибкой."
    } else "Подтверждено: " + last.getString("type") + " · " + last.getString("outcome") + "\n" +
            last.getString("task_id") + "\n" + last.getJSONObject("detail").toString()
    val error = journal?.optString("last_error").orEmpty()
    return current + "\n" + summary + if (error.isEmpty()) "" else "\nПоследняя ошибка: " + error
}

package cloud.poptracker.farm

import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID
import kotlin.random.Random

internal const val COLLECTION_TYPE = "COLLECT_TEST_DATA"

internal fun collectSyntheticVideos(job: JSONObject, payload: JSONObject, createdAt: String): JSONObject {
    val count = payload.opt("record_count")
    if (payload.opt("dataset") != "synthetic_videos" || count !is Int || count !in 10..100) {
        throw TaskProblem("INVALID_PAYLOAD", "Invalid collection parameters")
    }
    val taskId = job.getString("task_id")
    val batchId = UUID.nameUUIDFromBytes("synthetic_videos/$taskId".toByteArray(Charsets.UTF_8))
    val random = Random(batchId.mostSignificantBits)
    val utc = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }
    val collectionMs = requireNotNull(utc.parse(createdAt)).time
    val records = JSONArray()
    for (i in 1..count) {
        val suffix = i.toString().padStart(3, '0')
        val views = random.nextInt(100, 100_001)
        val published = Date(collectionMs - i * 60_000L)
        records.put(JSONObject().put("external_id", "test_video_$suffix").put("author_id", "test_author_$suffix")
            .put("published_at", utc.format(published)).put("description", "Synthetic test video $suffix")
            .put("views", views).put("likes", views / 10).put("comments", views / 50)
            .put("shares", views / 100).put("collected_at", createdAt))
    }
    return JSONObject().put("task_id", taskId).put("attempt_id", job.getString("attempt_id"))
        .put("device_id", job.getString("device_id")).put("batch_id", batchId.toString())
        .put("schema_version", 1).put("records", records).put("created_at", createdAt)
}

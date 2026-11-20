package cloud.poptracker.farm

import org.json.JSONArray
import org.json.JSONObject
import java.nio.charset.CharacterCodingException
import java.security.MessageDigest

internal const val ARCHIVE_COMMENTS = "ARCHIVE_COMMENTS_V1"
private val archiveId = Regex("[0-9]{1,32}")
private const val archivePayloadLimit = 32768

private fun invalidArchive(): Nothing = throw TaskProblem("INVALID_PAYLOAD", "Invalid archived comment batch")

private fun id(value: Any?): String =
    (value as? String)?.takeIf(archiveId::matches) ?: invalidArchive()

// Byte count for PHP's compact JSON with unescaped Unicode and slashes. U+2028/U+2029
// and control characters are still escaped. The digest always uses the unescaped bytes.
private fun escapedTextBytes(text: String, bytes: ByteArray): Int {
    var length = bytes.size
    for (c in text) {
        length += when (c) {
            '"', '\\', '\b', '\t', '\n', '\u000c', '\r' -> 1
            '\u2028', '\u2029' -> 3
            in '\u0000'..'\u001f' -> 5
            else -> 0
        }
    }
    return length
}

internal fun archiveComments(payload: JSONObject): JSONObject {
    val videoId = id(payload.opt("video_id"))
    val rows = payload.optJSONArray("comments") ?: invalidArchive()
    if (rows.length() !in 1..20) invalidArchive()
    val seen = mutableSetOf<String>()
    val digest = MessageDigest.getInstance("SHA-256")
    val results = JSONArray()
    var size = """{"video_id":"","comments":[]}""".length + videoId.length
    for (index in 0 until rows.length()) {
        val row = rows.optJSONObject(index) ?: invalidArchive()
        if (row.length() != 2) invalidArchive()
        val commentId = id(row.opt("id"))
        if (!seen.add(commentId)) invalidArchive()
        val text = row.opt("text") as? String ?: invalidArchive()
        if (text.isEmpty() || text.length > archivePayloadLimit) invalidArchive()
        val bytes = try {
            text.encodeToByteArray(throwOnInvalidSequence = true)
        } catch (_: CharacterCodingException) {
            invalidArchive()
        }
        size += """{"id":"","text":""}""".length + commentId.length + escapedTextBytes(text, bytes)
        if (index > 0) size++
        if (size > archivePayloadLimit) invalidArchive()
        results.put(JSONObject().put("id", commentId).put("sha256", digest.digest(bytes).toHexString()))
    }
    return JSONObject().put("video_id", videoId).put("comments", results)
}

internal fun matchesArchiveReceipt(expected: JSONObject, stored: JSONObject?): Boolean {
    val received = stored?.optJSONArray("comments") ?: return false
    val sent = expected.getJSONArray("comments")
    if (stored.opt("video_id") != expected.getString("video_id") || received.length() != sent.length()) return false
    return (0 until sent.length()).all { index ->
        val input = sent.getJSONObject(index)
        val output = received.optJSONObject(index)
        output?.opt("id") == input.getString("id") && output.opt("sha256") == input.getString("sha256")
    }
}

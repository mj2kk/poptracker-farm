package cloud.poptracker.farm.ipc

import org.json.JSONArray
import org.json.JSONObject
import java.security.MessageDigest

/** Same batch envelope as the Farm region flow, restricted to synthetic identities. */
internal object TestBatchContract {
    const val TASK = "TEST_AUTHOR_GEO_V1"
    const val AUTHORITY = "cloud.poptracker.farm.testprovider.batches"
    const val SOURCE = "synthetic_test_provider"
    val regions = listOf("ZA", "BR", "KZ", "AU", "GB", "CA", "NG", "ZA", "US", "KE")
    val controls = mapOf("test_control_au" to ("test_control_video_au" to "AU"),
        "test_control_za" to ("test_control_video_za" to "ZA"))

    data class Member(val uid: String, val vid: String, val role: String, val region: String)
    data class Scope(val id: String, val digest: String, val members: List<Member>)

    fun digest(members: List<Member>): String = sha(members.joinToString("", transform = {
        "${it.role}|${it.uid}|${it.vid}\n"
    }).toByteArray(Charsets.UTF_8))

    fun sha(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256").digest(bytes)
        .joinToString("") { "%02x".format(it) }

    fun request(id: String, number: Int): JSONObject {
        require(number in 1..10)
        val suffix = number.toString().padStart(3, '0')
        val members = listOf(Member("test_author_$suffix", "test_video_$suffix", "target", regions[number - 1])) +
            controls.map { (uid, value) -> Member(uid, value.first, "control", value.second) }
        return JSONObject().put("version", 1).put("request_id", id).put("scope_sha256", digest(members))
            .put("members", JSONArray().apply { members.forEach { put(JSONObject().put("uid", it.uid)
                .put("vid", it.vid).put("role", it.role)) } })
    }

    fun parse(raw: String): Scope {
        require(raw.length in 1..8192) { "invalid_size" }
        val o = JSONObject(raw)
        require(o.keys().asSequence().toSet() == setOf("version", "request_id", "scope_sha256", "members")) { "invalid_fields" }
        val id = o.get("request_id") as String
        require(RegionContract.requestIdPattern.matches(id) && o.get("version") == 1) { "invalid_version_or_id" }
        val rows = o.getJSONArray("members")
        require(rows.length() in 3..12) { "invalid_count" }
        val members = (0 until rows.length()).map { i ->
            val row = rows.getJSONObject(i)
            require(row.keys().asSequence().toSet() == setOf("uid", "vid", "role")) { "invalid_member_fields" }
            val uid = row.get("uid") as String
            val vid = row.get("vid") as String
            val role = row.get("role") as String
            val control = controls[uid]
            val region = if (role == "control" && control?.first == vid) control.second else {
                require(role == "target" && uid.matches(Regex("test_author_00[1-9]|test_author_010")) &&
                    vid == uid.replace("author", "video")) { "synthetic_identity_required" }
                regions[uid.takeLast(3).toInt() - 1]
            }
            Member(uid, vid, role, region)
        }
        require(members.map { it.uid }.toSet().size == members.size &&
            members.map { it.vid }.toSet().size == members.size &&
            members.filter { it.role == "control" }.map { it.uid }.toSet() == controls.keys) { "duplicate_or_missing_control" }
        val digest = digest(members)
        require(o.get("scope_sha256") == digest) { "scope_mismatch" }
        return Scope(id, digest, members)
    }
}

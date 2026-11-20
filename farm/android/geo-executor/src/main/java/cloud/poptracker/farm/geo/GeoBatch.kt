package cloud.poptracker.farm.geo

import org.json.JSONArray
import org.json.JSONObject
import java.security.MessageDigest
import java.util.UUID

internal data class PairId(val uid: String, val vid: String)
internal data class Member(val pair: PairId, val control: Boolean)
internal data class GeoBatch(val requestId: String, val members: List<Member>) {
    val digest: String get() {
        val material = members.joinToString("\n", postfix = "\n") {
            (if (it.control) "control" else "target") + "|" + it.pair.uid + "|" + it.pair.vid
        }
        return MessageDigest.getInstance("SHA-256").digest(material.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }
    }
    val targetCount get() = members.count { !it.control }
}

internal val controls = mapOf(
    PairId("7632224740756898837", "7678277351934856466") to "AU",
    PairId("7460188221663233029", "7678816459077766418") to "ZA",
)
private val numericId = Regex("[1-9][0-9]{0,31}")
private val countryCode = Regex("[A-Z]{2}")
private val sha256 = Regex("[0-9a-f]{64}")

private fun text(o: JSONObject, name: String): String = o.get(name) as String
private fun pair(o: JSONObject) = PairId(text(o, "uid"), text(o, "vid")).also {
    require(numericId.matches(it.uid) && numericId.matches(it.vid))
}

internal fun parseBatch(raw: String): GeoBatch {
    require(raw.length in 1..8192)
    val obj = JSONObject(raw)
    val id = text(obj, "request_id")
    require(UUID.fromString(id).toString() == id && obj.get("version") == 1)
    val rows = obj.getJSONArray("members")
    require(rows.length() in 3..12)
    val members = (0 until rows.length()).map { i ->
        val row = rows.getJSONObject(i)
        val role = text(row, "role")
        require(role == "target" || role == "control")
        Member(pair(row), role == "control")
    }
    require(members.map { it.pair.uid }.distinct().size == members.size)
    require(members.map { it.pair.vid }.distinct().size == members.size)
    require(members.filter { it.control }.map { it.pair }.toSet() == controls.keys)
    return GeoBatch(id, members).also { batch ->
        require(text(obj, "scope_sha256") == batch.digest)
    }
}

/** Accept observations only from the signed Farm source selected by the Binder receiver. */
internal fun checkedObservations(batch: GeoBatch, response: JSONObject, nonce: String, start: Long, end: Long): JSONArray {
    require(response.get("version") == 1 && text(response, "request_id") == batch.requestId &&
        text(response, "nonce") == nonce && text(response, "scope_sha256") == batch.digest)
    val rows = response.getJSONArray("observations")
    require(rows.length() == batch.members.size)
    val byPair = batch.members.associateBy { it.pair }
    val seen = mutableSetOf<PairId>()
    val output = JSONArray()
    for (i in 0 until rows.length()) {
        val row = rows.getJSONObject(i)
        val id = pair(row)
        val member = requireNotNull(byPair[id])
        require(seen.add(id))
        val whenObserved = row.get("observed_ms")
        require(whenObserved is Long && whenObserved in start..end)
        require(text(row, "field") == "author.region" && sha256.matches(text(row, "evidence_sha256")))
        require(row.has("raw_region") && row.has("typed_region"))
        val empty = row.isNull("raw_region") && row.isNull("typed_region")
        val result = JSONObject().put("uid", id.uid).put("vid", id.vid)
            .put("observed_ms", whenObserved).put("evidence_sha256", row.getString("evidence_sha256"))
            .put("role", if (member.control) "control" else "target")
        if (empty) {
            require(!member.control)
            result.put("region", JSONObject.NULL)
        } else {
            val country = text(row, "raw_region")
            require(country == text(row, "typed_region") && countryCode.matches(country) && country !in setOf("XX","ZZ"))
            require(!member.control || controls[id] == country)
            result.put("region", country)
        }
        output.put(result)
    }
    return output
}

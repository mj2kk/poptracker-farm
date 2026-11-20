package cloud.poptracker.farm.geo

import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class GeoBatchTest {
    val nonce = "nonce"
    val now = 1795000000000L
    private fun scope(): JSONObject {
        val arr = JSONArray()
        for (p in controls.keys + PairId("1001","2001")) arr.put(JSONObject()
            .put("uid",p.uid).put("vid",p.vid).put("role",if (p in controls) "control" else "target"))
        val hash = java.security.MessageDigest.getInstance("SHA-256")
            .digest((0 until arr.length()).joinToString("\n", postfix="\n") { i ->
                val row = arr.getJSONObject(i)
                listOf("role","uid","vid").joinToString("|") { row.getString(it) }
            }.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it) }
        return JSONObject().put("scope_sha256",hash).put("version",1).put("request_id","00000000-0000-4000-8000-000000000001").put("members",arr)
    }
    private fun response(b: GeoBatch): JSONObject {
        val a = JSONArray()
        for (m in b.members) {
            val code = controls[m.pair] ?: "BR"
            a.put(JSONObject().put("uid",m.pair.uid).put("vid",m.pair.vid).put("observed_ms",now)
                .put("field","author.region").put("evidence_sha256","f".repeat(64)).put("raw_region",code).put("typed_region",code))
        }
        return JSONObject().put("version",1).put("request_id",b.requestId).put("nonce",nonce)
            .put("scope_sha256",b.digest).put("observations",a)
    }
    @Test fun freshBoundReplyAndEmptyTarget() {
        val b=parseBatch(scope().toString()); val r=response(b)
        assertEquals(3,checkedObservations(b,r,nonce,now,now).length())
        r.getJSONArray("observations").getJSONObject(2).put("raw_region",JSONObject.NULL).put("typed_region",JSONObject.NULL)
        assertTrue(checkedObservations(b,r,nonce,now,now).getJSONObject(2).isNull("region"))
    }
    @Test fun differentIdentityOldDataWrongControlOrReplayFails() {
        val b=parseBatch(scope().toString())
        for ((key,value) in listOf("uid" to "99","vid" to "654", "raw_region" to "BR", "typed_region" to "GB",
                "observed_ms" to (now-1), "field" to "locationCreated", "evidence_sha256" to "x")) {
            val r=response(b); r.getJSONArray("observations").getJSONObject(0).put(key,value)
            assertTrue(key,runCatching { checkedObservations(b,r,nonce,now,now) }.isFailure)
        }
        val r=response(b)
        assertTrue(runCatching { checkedObservations(b,r,"different",now,now) }.isFailure)
        val control=r.getJSONArray("observations").getJSONObject(0)
        control.put("raw_region","GB").put("typed_region","GB")
        assertTrue(runCatching { checkedObservations(b,r,nonce,now,now) }.isFailure)
    }
    @Test fun duplicateAndNumericCoercionRejected() {
        val s=scope();s.getJSONArray("members").put(s.getJSONArray("members").getJSONObject(2))
        assertTrue(runCatching { parseBatch(s.toString()) }.isFailure)
        val t=scope();t.getJSONArray("members").getJSONObject(2).put("uid",1001)
        assertTrue(runCatching { parseBatch(t.toString()) }.isFailure)
    }
}

package cloud.poptracker.farm

import org.junit.Assert.*
import org.junit.Test

class ProtocolTest {
    @Test fun originIsHttpsAndHasNoUserInfoPathOrFragment() {
        assertEquals("https://example.test:8443", validatedOrigin(" https://example.test:8443/ "))
        for (url in listOf("http://example.test", "https://login@example.test", "https://example.test/path",
            "https://example.test?token=x", "https://example.test#x", "https://example.test:0")) {
            assertTrue(runCatching { validatedOrigin(url) }.isFailure)
        }
    }
    @Test fun retryDelayIsBounded() {
        for (i in 0..30) assertTrue(retryMillis(i) in 2_500L..60_000L)
    }
    @Test fun serializesHeartbeatWithOptionalBattery() {
        val time = "2026-11-19T15:00:00Z"
        val json = org.json.JSONObject(heartbeatFields("device-1", 54, time).toString())
        assertEquals("device-1", json.getString("device_id"))
        assertEquals(BuildConfig.VERSION_NAME, json.getString("agent_version"))
        assertEquals(time, json.getString("sent_at"))
        assertEquals(54, json.getInt("battery_percent"))
        assertFalse(heartbeatFields("device-1", null, time).has("battery_percent"))
    }
    @Test fun errorsAreActionable() {
        assertTrue(ApiFailure(401).message!!.contains("токен"))
        assertTrue(ApiFailure(429).message!!.contains("минуту"))
    }
}

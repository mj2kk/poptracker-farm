package cloud.poptracker.farm

import android.util.Base64
import androidx.test.platform.app.InstrumentationRegistry
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class FreshGeoDeviceTest {
    @Test fun agentReceivesUnavailableReceiptForPreparedFarmBatch() {
        val args = InstrumentationRegistry.getArguments()
        val encoded = requireNotNull(args.getString("farmBatch"))
        val payload = JSONObject(String(Base64.decode(encoded, Base64.URL_SAFE or Base64.NO_WRAP), Charsets.UTF_8))
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val client = FreshGeoClient(context)
        assertFalse(client.ready())
        val job = JSONObject().put("schema_version", 1).put("result_protocol", 2)
            .put("task_id", payload.getString("request_id")).put("payload", payload)
        val error = runCatching { client.execute(job) }.exceptionOrNull()
        assertTrue(error is TaskProblem)
        assertEquals("geo_source_unavailable", error?.message)
    }
}

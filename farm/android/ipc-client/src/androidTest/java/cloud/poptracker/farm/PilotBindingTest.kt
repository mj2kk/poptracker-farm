package cloud.poptracker.farm

import android.os.Bundle
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import cloud.poptracker.farm.ipc.RegionContract as C
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class PilotBindingTest {
    private val context = InstrumentationRegistry.getInstrumentation().targetContext
    private val id = "00000000-0000-4000-8000-000000000001"
    private val job get() = JSONObject().put("task_id", id).put("type", C.CAPABILITY)
        .put("schema_version", 1).put("result_protocol", 2).put("payload", JSONObject().put("author_uid", C.FIXTURE))

    @Test fun actualRemoteBinderAndNewBindingBothReturnCorrelatedFixture() {
        repeat(2) {
            val result = executeIpcTask(context, job)
            assertEquals("ZA", result.getString("region"))
            assertEquals(C.SOURCE, result.getString("source"))
            assertEquals(id, result.getString("request_id"))
        }
    }

    @Test fun providerRejectsUnknownOperationVersionAndAuthorOverBinder() {
        withTestProvider(context) { binder ->
            assertEquals("unsupported_operation", binder.query(1,id,"OTHER",C.FIXTURE).getString("code"))
            assertEquals("unsupported_version", binder.query(2,id,C.OPERATION,C.FIXTURE).getString("code"))
            assertEquals("unsupported_author", binder.query(1,id,C.OPERATION,"123").getString("code"))
        }
    }

    @Test fun mismatchedCorrelationCannotBeDelivered() {
        val bad = Bundle().apply {
            putString("region", "ZA"); putString("source", C.SOURCE); putString("status", "success")
            putString("request_id", "00000000-0000-4000-8000-000000000002"); putString("author_uid", C.FIXTURE)
        }
        try { validatePilotReply(bad, id); fail("Bad request ID accepted") }
        catch (error: TaskProblem) { assertEquals("EXECUTION_FAILED", error.code) }
    }
}

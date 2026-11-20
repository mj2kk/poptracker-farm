package cloud.poptracker.farm

import android.net.Uri
import androidx.test.platform.app.InstrumentationRegistry
import cloud.poptracker.farm.ipc.TestBatchContract as C
import org.junit.Assert.*
import org.junit.Test
import java.util.UUID

class TestBatchDeviceTest {
    private val context = InstrumentationRegistry.getInstrumentation().targetContext
    private val client = TestBatchClient(context)
    private fun id() = UUID.randomUUID().toString()
    private fun stats() = context.contentResolver.call(Uri.parse("content://${C.AUTHORITY}"), "stats_v1", null, null)!!

    @Test fun realContentProviderSerializesAllTenPairsAndReplaysOneReceipt() {
        val baseline = stats().getInt("work_count")
        for (n in 1..10) {
            val request = C.request(id(), n); val nonce = id()
            val first = client.query(request, nonce)
            val repeat = client.query(request, nonce)
            assertEquals(first.toString(), repeat.toString())
            assertEquals(C.regions[n - 1], first.getJSONArray("observations").getJSONObject(0).getString("region"))
        }
        assertEquals(baseline + 10, stats().getInt("work_count"))
    }

    @Test fun exceptionMalformedResponseAndNonceConflictAreReported() {
        for (fault in listOf("error", "malformed")) {
            assertTrue(runCatching { client.query(C.request(id(), 1), id(), fault) }.exceptionOrNull() is TaskProblem)
        }
        val request = C.request(id(), 2)
        client.query(request, id())
        assertTrue(runCatching { client.query(request, id()) }.exceptionOrNull() is TaskProblem)
    }

    @Test fun timeoutKeepsOnlyOneOutstandingTransactionAndRecovers() {
        val request = C.request(id(), 3); val nonce = id()
        val problem = runCatching { client.query(request, nonce, "delay", 100) }.exceptionOrNull()
        assertEquals("test_provider_timeout", problem?.message)
        assertEquals("test_provider_busy", runCatching { client.query(request, nonce) }.exceptionOrNull()?.message)
        Thread.sleep(1700)
        assertEquals(C.regions[2], client.query(request, nonce).getJSONArray("observations").getJSONObject(0).getString("region"))
    }

    @Test fun providerProcessDeathInvalidatesCallAndStartsANewProcess() {
        val before = stats().getInt("pid")
        val request = C.request(id(), 4); val nonce = id()
        val first = client.query(request, nonce)
        assertTrue(runCatching { client.query(C.request(id(), 5), id(), "kill") }.exceptionOrNull() is TaskProblem)
        val after = stats().getInt("pid")
        assertNotEquals(before, after)
        assertEquals(first.toString(), client.query(request, nonce).toString())
    }
}

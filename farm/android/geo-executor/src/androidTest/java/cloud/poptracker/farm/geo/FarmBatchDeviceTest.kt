package cloud.poptracker.farm.geo

import android.net.Uri
import android.os.Bundle
import android.util.Base64
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test

class FarmBatchDeviceTest {
    @Test fun handOffPreparedBatch() {
        val args = InstrumentationRegistry.getArguments()
        val encoded = requireNotNull(args.getString("farmBatch"))
        val raw = String(Base64.decode(encoded, Base64.URL_SAFE or Base64.NO_WRAP), Charsets.UTF_8)
        val batch=parseBatch(raw)
        val ctx=InstrumentationRegistry.getInstrumentation().targetContext
        val result=ctx.contentResolver.call(Uri.parse("content://cloud.poptracker.farm.geoexecutor"),"observe_batch_v1",
            null,Bundle().apply { putString("batch",raw) })!!
        assertEquals(batch.requestId,result.getString("request_id"))
        assertEquals(batch.digest,result.getString("scope_sha256"))
        assertEquals(batch.targetCount,result.getInt("target_count"))
        assertEquals(2,result.getInt("control_count"))
        assertEquals("source_unavailable",result.getString("status"))
        assertEquals(0,result.getInt("source_calls"))
        assertFalse(result.containsKey("observations"))
        InstrumentationRegistry.getInstrumentation().sendStatus(0, Bundle().apply {
            putString("scope",batch.digest)
            putString("request",batch.requestId)
            putString("received_by_executor",batch.members.size.toString())
            putString("source_status",result.getString("status"))
        })
    }
}

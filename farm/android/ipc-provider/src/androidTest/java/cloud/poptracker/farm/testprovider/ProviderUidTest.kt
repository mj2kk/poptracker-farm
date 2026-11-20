package cloud.poptracker.farm.testprovider

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.ServiceConnection
import android.os.IBinder
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import cloud.poptracker.farm.ipc.ITestRegion
import cloud.poptracker.farm.ipc.RegionContract as C
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.concurrent.CompletableFuture
import java.util.concurrent.TimeUnit

@RunWith(AndroidJUnit4::class)
class ProviderUidTest {
    // Instrumentation runs with the provider's UID, signed and permission-granted, outside service process :ipc.
    @Test fun signedButNonClientUidCannotInvokeAidl() {
        val ctx = InstrumentationRegistry.getInstrumentation().targetContext
        assertEquals(C.PROVIDER, ctx.packageName)
        val connected = CompletableFuture<IBinder>()
        val conn = object : ServiceConnection {
            override fun onServiceConnected(name: ComponentName, binder: IBinder) { connected.complete(binder) }
            override fun onServiceDisconnected(name: ComponentName) { }
        }
        assertTrue(ctx.bindService(Intent().setComponent(ComponentName(C.PROVIDER,C.SERVICE)),conn,Context.BIND_AUTO_CREATE))
        try {
            val binder = ITestRegion.Stub.asInterface(connected.get(5, TimeUnit.SECONDS))
            try {
                binder.query(1,"00000000-0000-4000-8000-000000000001",C.OPERATION,C.FIXTURE)
                fail("Sibling caller was accepted")
            } catch (_: SecurityException) { /* Binder denied this UID. */ }
        } finally { ctx.unbindService(conn) }
    }
}

class BatchProviderUidTest {
    @org.junit.Test fun signedProviderSiblingUidCannotInvokeContentProvider() {
        val ctx = androidx.test.platform.app.InstrumentationRegistry.getInstrumentation().targetContext
        try {
            ctx.contentResolver.call(android.net.Uri.parse("content://cloud.poptracker.farm.testprovider.batches"), "stats_v1", null, null)
            org.junit.Assert.fail("Sibling UID accepted")
        } catch (_: SecurityException) { }
    }
}

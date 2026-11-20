package cloud.poptracker.farm.testprovider

import android.app.Service
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Binder
import android.os.Bundle
import android.os.IBinder
import android.os.Process
import cloud.poptracker.farm.ipc.ITestRegion
import cloud.poptracker.farm.ipc.RegionContract as Contract

class TestRegionService : Service() {
    private val service = object : ITestRegion.Stub() {
        override fun query(version: Int, requestId: String?, operation: String?, authorUid: String?): Bundle {
            enforceCallingPermission(Contract.PERMISSION, "Signature permission required")
            val uid = Binder.getCallingUid()
            val packages = packageManager.getPackagesForUid(uid)
            val sameSigner = packageManager.checkSignatures(uid, Process.myUid()) == PackageManager.SIGNATURE_MATCH
            if (!callerAllowed(sameSigner, packages)) throw SecurityException("Untrusted caller UID")

            val fields = syntheticRegion(version, requestId, operation, authorUid)
            return Bundle().apply { fields.forEach { (name, value) -> putString(name, value) } }
        }
    }

    override fun onBind(intent: Intent): IBinder = service
}

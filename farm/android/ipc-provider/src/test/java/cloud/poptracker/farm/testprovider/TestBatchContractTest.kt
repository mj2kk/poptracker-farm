package cloud.poptracker.farm.testprovider

import cloud.poptracker.farm.ipc.TestBatchContract as C
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.util.UUID

class TestBatchContractTest {
    @Test fun allTenSyntheticPairsHaveIndependentRegionsAndTwoControls() {
        for (n in 1..10) {
            val scope = C.parse(C.request(UUID.randomUUID().toString(), n).toString())
            assertEquals(C.regions[n - 1], scope.members.single { it.role == "target" }.region)
            assertEquals(setOf("AU", "ZA"), scope.members.filter { it.role == "control" }.map { it.region }.toSet())
        }
    }
    @Test fun realIdentifiersNumericCoercionAndChangedScopeAreRejected() {
        for (mutation in listOf<(JSONObject)->Unit>(
            { it.getJSONArray("members").getJSONObject(0).put("uid", "1000000000000000001") },
            { it.getJSONArray("members").getJSONObject(0).put("uid", 1) },
            { it.getJSONArray("members").getJSONObject(0).put("vid", "test_video_002") },
            { it.put("scope_sha256", "0".repeat(64)) },
            { it.put("version", 2) },
            { it.getJSONArray("members").put(it.getJSONArray("members").getJSONObject(0)) }
        )) {
            val r = C.request(UUID.randomUUID().toString(), 1); mutation(r)
            assertTrue(runCatching { C.parse(r.toString()) }.isFailure)
        }
    }
}

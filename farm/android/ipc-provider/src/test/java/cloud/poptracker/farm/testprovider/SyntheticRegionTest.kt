package cloud.poptracker.farm.testprovider

import cloud.poptracker.farm.ipc.RegionContract as C
import org.junit.Assert.*
import org.junit.Test

class SyntheticRegionTest {
    private val id = "00000000-0000-4000-8000-000000000001"

    @Test fun onlyExactNonSharedUidAndMatchingSignerAreAccepted() {
        assertTrue(callerAllowed(true, arrayOf(C.CLIENT)))
        assertFalse(callerAllowed(false, arrayOf(C.CLIENT)))
        assertFalse(callerAllowed(true, arrayOf("another.signed.app")))
        assertFalse(callerAllowed(true, arrayOf(C.CLIENT, "shared.uid.app")))
        assertFalse(callerAllowed(true, null))
    }

    @Test fun syntheticResultIsCorrelatedAndStable() {
        val result = syntheticRegion(1, id, C.OPERATION, C.FIXTURE)
        assertEquals(mapOf("request_id" to id, "status" to "success", "author_uid" to C.FIXTURE,
            "region" to "ZA", "source" to C.SOURCE), result)
        assertEquals(result, syntheticRegion(1, id, C.OPERATION, C.FIXTURE))
    }

    @Test fun unrelatedIdsOperationsAndVersionsProduceNoRegion() {
        val errors = listOf(
            syntheticRegion(1, null, C.OPERATION, C.FIXTURE) to "invalid_request_id",
            syntheticRegion(2, id, C.OPERATION, C.FIXTURE) to "unsupported_version",
            syntheticRegion(1, id, "OTHER", C.FIXTURE) to "unsupported_operation",
            syntheticRegion(1, id, C.OPERATION, "123456") to "unsupported_author"
        )
        errors.forEach { (result, expected) ->
            assertEquals("error", result["status"])
            assertEquals(expected, result["code"])
            assertFalse(result.containsKey("region"))
        }
    }
}

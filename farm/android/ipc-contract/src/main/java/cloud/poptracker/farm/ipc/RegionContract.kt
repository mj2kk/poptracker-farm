package cloud.poptracker.farm.ipc

internal object RegionContract {
    const val VERSION = 1
    const val CLIENT = "cloud.poptracker.farm.ipcpoc"
    const val PROVIDER = "cloud.poptracker.farm.testprovider"
    const val SERVICE = "$PROVIDER.TestRegionService"
    const val PERMISSION = "$PROVIDER.permission.GET_TEST_REGION"
    const val CAPABILITY = "GET_TEST_REGION_V1"
    const val OPERATION = "GET_TEST_REGION"
    const val FIXTURE = "test_author_001"
    const val SOURCE = "synthetic_test_provider"
    val requestIdPattern = Regex("[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}")
}

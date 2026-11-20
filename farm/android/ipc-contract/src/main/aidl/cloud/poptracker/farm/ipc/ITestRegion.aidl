package cloud.poptracker.farm.ipc;

import android.os.Bundle;

// v1 accepts a canonical request UUID and only the synthetic GET_TEST_REGION operation.
// Responses contain primitive strings. The service verifies the caller on every transaction.
interface ITestRegion {
    Bundle query(int version, String requestId, String operation, String authorUid);
}

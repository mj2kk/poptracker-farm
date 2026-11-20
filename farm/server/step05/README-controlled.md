# Controlled ContentProvider tests

The lab uses the existing Farm TaskEngine and Vault, two first-party APKs, a real remote ContentProvider, HTTPS, and the separate `poptracker_farm_test_step05` database. The provider has no INTERNET permission. The contract accepts only ten synthetic target identities and two synthetic controls. Every delivered result includes `source=synthetic_test_provider` and `synthetic=true`.

## Build

Run from `android` with the Android Studio JDK and SDK configured. Offline mode requires the pinned dependencies in the local cache.

```sh
./gradlew --offline :app:assembleDebug :app:testDebugUnitTest :geo-executor:assembleDebug :geo-executor:testDebugUnitTest :ipc-client:assembleDebug :ipc-provider:assembleDebug :ipc-provider:testDebugUnitTest :ipc-client:assembleDebugAndroidTest :ipc-provider:assembleDebugAndroidTest
```

Install the client and provider APKs and their androidTest APKs on a disposable emulator. Run `TestBatchDeviceTest`, `PilotBindingTest`, `BatchProviderUidTest`, and `ProviderUidTest` with AndroidJUnitRunner. These exercise the actual ContentProvider, the previous AIDL service, caller authorization, timeouts, replay, and provider process termination.

## Server

`assemble.sh` copies the existing Farm transport and applies `tasks.patch` with fuzz zero. For a fresh dedicated database apply `up.sql`, `002_tasks_up.sql`, `004_result_delivery_up.sql`, and `step05/test.sql`. The isolated server must include `test_batch.php` and `lab.php`, and its device_tasks ENUM must include `GET_TEST_REGION_V1` and `TEST_AUTHOR_GEO_V1`. Production Farm sources and tables are outside this deployment.

`test_batch_test.php` validates the synthetic payload and results without a database. `test.php` runs the earlier transport checks in the dedicated schema. `lab.php` restricts queue administration to a test device with the expected lab label.

## Network run

Set `FARM_LAB_OUTPUT` to a new private output directory and `FARM_LAB_SERIAL` to the disposable emulator. Administrative SSH access is required for test enrollment and queue preparation. Set `FARM_LAB_SSH_HOST` to your test server. Optional settings are `FARM_LAB_SSH_SOCKET`, `FARM_LAB_ANDROID` and `FARM_LAB_ADB`. The Android client uses the normal enrollment flow and stores its own token in Vault. The enrollment code is retained only in the private `run-private.json` file.

Run these commands from the project root sequentially and stop on the first failure.

```sh
python3 android/ipc-client/run_controlled.py prepare
python3 android/ipc-client/run_controlled.py ten
python3 android/ipc-client/run_controlled.py recovery
python3 android/ipc-client/run_controlled.py unknown
python3 android/ipc-client/run_controlled.py export
python3 android/ipc-client/run_controlled.py revoke
```

`prepare` installs only the instrumentation APK, so the two apps and provider test APK must already be installed. `ten` executes ten distinct server tasks through to their MySQL receipts. `recovery` simulates a lost acknowledgement after a real server commit, stops the client, and delivers the durable packet again. `unknown` intentionally kills the client after the provider reply and before saving the body. Recovery must report `EXECUTION_UNKNOWN` and perform zero further provider calls. Its failed task status is an expected fault-test result.

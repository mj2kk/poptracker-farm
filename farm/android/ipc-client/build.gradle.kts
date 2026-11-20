plugins {
    id("com.android.application") version "9.5.1"
}

android {
    // The test client builds the Farm delivery sources, under its own application ID.
    namespace = "cloud.poptracker.farm"
    compileSdk { version = release(37) }
    defaultConfig {
        applicationId = "cloud.poptracker.farm.ipcpoc"
        minSdk = 26
        targetSdk = 37
        versionCode = 2
        versionName = "0.2.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
    sourceSets.getByName("main") {
        kotlin.srcDir("../app/src/main/java")
        kotlin.srcDir("../ipc-contract/src/main/java")
        res.srcDir("../app/src/main/res")
        aidl.srcDir("../ipc-contract/src/main/aidl")
    }
    buildFeatures { aidl = true; buildConfig = true }
}

dependencies {
    androidTestImplementation("androidx.test.ext:junit:1.1.5")
    androidTestImplementation("androidx.test:runner:1.5.2")
}

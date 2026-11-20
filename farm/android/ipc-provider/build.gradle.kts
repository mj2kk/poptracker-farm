plugins {
    id("com.android.application") version "9.5.1"
}

android {
    namespace = "cloud.poptracker.farm.testprovider"
    compileSdk { version = release(37) }
    defaultConfig {
        applicationId = "cloud.poptracker.farm.testprovider"
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
        kotlin.srcDir("../ipc-contract/src/main/java")
        aidl.srcDir("../ipc-contract/src/main/aidl")
    }
    buildFeatures { aidl = true }
}

dependencies {
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
    androidTestImplementation("androidx.test.ext:junit:1.1.5")
    androidTestImplementation("androidx.test:runner:1.5.2")
}

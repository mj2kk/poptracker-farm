plugins {
    id("com.android.application") version "9.5.1"
}

android {
    namespace = "cloud.poptracker.farm"
    compileSdk { version = release(37) }
    defaultConfig {
        applicationId = "cloud.poptracker.farm"
        minSdk = 23
        targetSdk = 37
        versionCode = 10
        versionName = "0.6.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
    buildFeatures { buildConfig = true }
}
dependencies {
    androidTestImplementation("androidx.test.ext:junit:1.1.5")
    androidTestImplementation("androidx.test:runner:1.5.2")
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
}

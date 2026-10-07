plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

val smbReleaseKeystore = providers.environmentVariable("SMB_RELEASE_KEYSTORE").orNull
    ?: rootProject.file("../.secrets/android/SMB-release.jks").absolutePath
val smbReleaseStorePassword = providers.environmentVariable("SMB_RELEASE_STORE_PASSWORD").orNull.orEmpty()
val smbReleaseKeyAlias = providers.environmentVariable("SMB_RELEASE_KEY_ALIAS").orNull.orEmpty()
val smbReleaseKeyPassword = providers.environmentVariable("SMB_RELEASE_KEY_PASSWORD").orNull.orEmpty()

android {
    namespace = "com.smb.lacak"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.smb.lacak"
        minSdk = 26
        targetSdk = 36
        versionCode = 4
        versionName = "1.1.0-beta.3"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        buildConfigField("String", "API_BASE_URL", "\"https://api.lacaksmbbot.com\"")
        buildConfigField("String", "WS_URL", "\"wss://ws.lacaksmbbot.com/ws\"")
    }

    signingConfigs {
        create("smbRelease") {
            storeFile = file(smbReleaseKeystore)
            storePassword = smbReleaseStorePassword
            keyAlias = smbReleaseKeyAlias
            keyPassword = smbReleaseKeyPassword
        }
    }

    buildTypes {
        getByName("release") {
            signingConfig = signingConfigs.getByName("smbRelease")
            isMinifyEnabled = false
        }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    testOptions {
        unitTests.isIncludeAndroidResources = true
    }
}

tasks.register("verifySmbReleaseSigning") {
    doLast {
        check(smbReleaseStorePassword.isNotBlank()) { "SMB_RELEASE_STORE_PASSWORD must be provided for a signed SMB release." }
        check(smbReleaseKeyAlias.isNotBlank()) { "SMB_RELEASE_KEY_ALIAS must be provided for a signed SMB release." }
        check(smbReleaseKeyPassword.isNotBlank()) { "SMB_RELEASE_KEY_PASSWORD must be provided for a signed SMB release." }
        check(file(smbReleaseKeystore).isFile) { "SMB release keystore was not found at the configured path." }
    }
}

tasks.configureEach {
    if (name == "assembleRelease" || name.startsWith("packageRelease")) {
        dependsOn("verifySmbReleaseSigning")
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.material)
    implementation(libs.androidx.lifecycle.viewmodel.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.okhttp)
    implementation(libs.kotlinx.coroutines.android)

    testImplementation(libs.junit)
    testImplementation(libs.mockwebserver)
    androidTestImplementation(libs.androidx.test.junit)
    androidTestImplementation(libs.androidx.test.runner)
    androidTestImplementation(libs.androidx.test.espresso)
    androidTestImplementation(libs.mockwebserver)
}

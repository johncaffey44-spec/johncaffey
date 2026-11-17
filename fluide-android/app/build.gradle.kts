import java.util.Properties

plugins {
    id("com.android.application")
}

// Signature « release » : lue dans keystore.properties (non versionné) si présent.
// Sans ce fichier, l'APK release est signé avec la clé de débogage locale
// (il reste NON débogable, ce qui compte pour la justesse des mesures).
val keystoreProps = Properties().apply {
    val f = rootProject.file("keystore.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}

android {
    namespace = "app.fluide"
    compileSdk = 36

    defaultConfig {
        applicationId = "app.fluide"
        minSdk = 26
        targetSdk = 36
        versionCode = 2
        versionName = "1.1"
    }

    signingConfigs {
        if (keystoreProps.getProperty("storeFile") != null) {
            create("release") {
                storeFile = rootProject.file(keystoreProps.getProperty("storeFile"))
                storePassword = keystoreProps.getProperty("storePassword")
                keyAlias = keystoreProps.getProperty("keyAlias")
                keyPassword = keystoreProps.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.findByName("release") ?: signingConfigs.getByName("debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    testOptions {
        unitTests {
            isIncludeAndroidResources = true
            all { test ->
                test.maxHeapSize = "2g"
                // Permet aux tests de déposer des captures d'écran (relecture visuelle).
                test.systemProperty("fluide.screenshots", rootProject.file("build/screenshots").absolutePath)
            }
        }
    }

    lint {
        // Appli uniquement en français : textes dans le code, pas de traduction.
        disable += setOf("SetTextI18n", "HardcodedText", "MissingTranslation")
        abortOnError = true
        checkReleaseBuilds = true
    }
}

dependencies {
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.robolectric:robolectric:4.18")
}

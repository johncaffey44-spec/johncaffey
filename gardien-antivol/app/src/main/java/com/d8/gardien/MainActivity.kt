package com.d8.gardien

import android.Manifest
import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.d8.gardien.admin.GardienDeviceAdminReceiver
import com.d8.gardien.net.Api
import com.d8.gardien.service.GardienService
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import org.json.JSONObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private val Blue = Color(0xFF1487C9)
private val BlueDark = Color(0xFF0E6DA4)

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val (du, dc) = parsePairing(intent?.dataString)
        setContent {
            MaterialTheme(
                colorScheme = lightColorScheme(primary = Blue, secondary = BlueDark)
            ) {
                Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
                    GardienRoot(du, dc)
                }
            }
        }
    }

    // Lien profond reçu alors que l'app tournait déjà (launchMode singleTask) : on repart pré-rempli.
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        recreate()
    }
}

/** Extrait (serveur, code) d'un contenu scanné ou d'un lien profond : URL d'install, lien gardien://, JSON, ou code brut. */
fun parsePairing(s: String?): Pair<String?, String?> {
    if (s.isNullOrBlank()) return null to null
    val t = s.trim()
    if (t.startsWith("{")) {
        try {
            val j = JSONObject(t)
            return (j.optString("url").ifBlank { null }) to (j.optString("code").ifBlank { null })?.uppercase()
        } catch (_: Exception) { }
    }
    if (t.contains("://")) {
        try {
            val uri = Uri.parse(t)
            val code = (uri.getQueryParameter("code") ?: uri.getQueryParameter("c"))?.uppercase()
            var url = uri.getQueryParameter("u") ?: uri.getQueryParameter("url")
            if (url.isNullOrBlank() && t.contains("gardien-api.php")) url = t.substringBefore("gardien-api.php") + "gardien-api.php"
            return (url?.ifBlank { null }) to code
        } catch (_: Exception) { }
    }
    return null to t.uppercase()
}

@Composable
private fun GardienRoot(initialUrl: String? = null, initialCode: String? = null) {
    val context = LocalContext.current
    val prefs = remember { Prefs(context) }
    var enrolled by remember { mutableStateOf(prefs.isEnrolled) }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
        Header()
        Spacer(Modifier.height(8.dp))
        if (!enrolled) {
            PairingForm(prefs, initialUrl, initialCode) {
                GardienService.start(context)
                enrolled = true
            }
        } else {
            StatusScreen(prefs) { enrolled = false }
        }
    }
}

@Composable
private fun Header() {
    Box(
        Modifier.fillMaxWidth().background(BlueDark).padding(horizontal = 20.dp, vertical = 22.dp)
    ) {
        Column {
            Text("🛡 Gardien", color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
            Text("Antivol — vos propres appareils", color = Color.White.copy(alpha = 0.85f), fontSize = 14.sp)
        }
    }
}

@Composable
private fun PairingForm(prefs: Prefs, initialUrl: String? = null, initialCode: String? = null, onPaired: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var url by remember { mutableStateOf(initialUrl?.takeIf { it.isNotBlank() } ?: prefs.serverUrl.ifBlank { "https://" }) }
    var code by remember { mutableStateOf(initialCode.orEmpty()) }
    var name by remember { mutableStateOf(Build.MODEL ?: "Mon appareil") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun runEnroll(u: String, cc: String) {
        error = null
        if (!u.startsWith("http") || cc.isBlank()) { error = "Renseignez l'adresse du serveur et le code."; return }
        busy = true
        scope.launch {
            val r = runCatching {
                withContext(Dispatchers.IO) {
                    Api.enroll(u.trim(), cc.trim(), name.trim(), Build.MANUFACTURER + " " + Build.MODEL)
                }
            }
            busy = false
            r.onSuccess {
                prefs.serverUrl = u.trim(); prefs.deviceId = it.deviceId; prefs.deviceToken = it.deviceToken
                prefs.deviceName = name.trim(); prefs.heartbeat = it.heartbeat; onPaired()
            }.onFailure { e -> error = e.message ?: "Échec de l'appairage" }
        }
    }

    // Appairage automatique à l'arrivée via le lien profond (QR -> page d'install -> « Ouvrir dans Gardien »).
    var autoTried by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        if (!autoTried && !initialUrl.isNullOrBlank() && !initialCode.isNullOrBlank()) {
            autoTried = true
            runEnroll(initialUrl, initialCode)
        }
    }

    val scanLauncher = rememberLauncherForActivityResult(ScanContract()) { res ->
        val c = res.contents
        if (c != null) {
            val (u, cc) = parsePairing(c)
            if (!u.isNullOrBlank()) url = u
            if (!cc.isNullOrBlank()) code = cc
        }
    }

    Column(Modifier.padding(20.dp)) {
        SectionCard {
            Text("Appairer cet appareil", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
            Spacer(Modifier.height(4.dp))
            Text(
                "Dans le tableau de bord Gardien, cliquez sur « Ajouter », puis scannez le QR ou saisissez l'adresse + le code.",
                fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.height(16.dp))
            Button(
                onClick = {
                    scanLauncher.launch(
                        ScanOptions().setBeepEnabled(false).setOrientationLocked(false)
                            .setPrompt("Scannez le QR d'appairage affiché dans le tableau de bord")
                    )
                },
                modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(10.dp)
            ) { Text("Scanner le QR d'appairage") }
            Spacer(Modifier.height(14.dp))
            Text("ou saisissez manuellement :", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(10.dp))
            OutlinedTextField(
                value = url, onValueChange = { url = it.trim() },
                label = { Text("Adresse du serveur (…/gardien-api.php)") },
                singleLine = true, modifier = Modifier.fillMaxWidth(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri)
            )
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = code, onValueChange = { code = it.uppercase() },
                label = { Text("Code d'appairage (ex. ABCD-1234)") },
                singleLine = true, modifier = Modifier.fillMaxWidth()
            )
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = name, onValueChange = { name = it },
                label = { Text("Nom de l'appareil") },
                singleLine = true, modifier = Modifier.fillMaxWidth()
            )
            error?.let {
                Spacer(Modifier.height(12.dp))
                Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp)
            }
            Spacer(Modifier.height(18.dp))
            Button(
                onClick = { runEnroll(url, code) },
                enabled = !busy, modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(10.dp)
            ) {
                if (busy) CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                else Text("Appairer")
            }
        }
        Spacer(Modifier.height(16.dp))
        InfoBanner("Après l'appairage, Gardien reste VISIBLE : icône dans le menu et notification permanente. Aucun mode caché.")
    }
}

@Composable
private fun StatusScreen(prefs: Prefs, onUnpair: () -> Unit) {
    val context = LocalContext.current

    var locationGranted by remember { mutableStateOf(hasPerm(context, Manifest.permission.ACCESS_FINE_LOCATION)) }
    var bgGranted by remember {
        mutableStateOf(
            Build.VERSION.SDK_INT < Build.VERSION_CODES.Q ||
                hasPerm(context, Manifest.permission.ACCESS_BACKGROUND_LOCATION)
        )
    }
    var notifGranted by remember {
        mutableStateOf(
            Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
                hasPerm(context, Manifest.permission.POST_NOTIFICATIONS)
        )
    }
    var adminActive by remember { mutableStateOf(GardienDeviceAdminReceiver.isActive(context)) }
    var cameraGranted by remember { mutableStateOf(hasPerm(context, Manifest.permission.CAMERA)) }

    val camLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { cameraGranted = it }
    val locLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        locationGranted = hasPerm(context, Manifest.permission.ACCESS_FINE_LOCATION)
    }
    val bgLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        bgGranted = it
    }
    val notifLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {
        notifGranted = it
    }
    val adminLauncher = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) {
        adminActive = GardienDeviceAdminReceiver.isActive(context)
    }

    Column(Modifier.padding(20.dp)) {
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(10.dp).background(Color(0xFF2C8F55), RoundedCornerShape(5.dp)))
                Spacer(Modifier.width(8.dp))
                Text("Protection active", fontWeight = FontWeight.SemiBold, fontSize = 17.sp)
            }
            Spacer(Modifier.height(6.dp))
            Text(prefs.deviceName, fontSize = 15.sp)
            Text(prefs.serverUrl, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(
                "Gardien tourne en tâche de fond, visible via la notification permanente.",
                fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(top = 8.dp)
            )
        }

        Spacer(Modifier.height(16.dp))
        Text("Autorisations", fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 8.dp))

        PermRow("Localisation", locationGranted) {
            val perms = mutableListOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)
            locLauncher.launch(perms.toTypedArray())
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            PermRow("Localisation en permanence", bgGranted, enabled = locationGranted) {
                bgLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            }
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            PermRow("Notifications", notifGranted) {
                notifLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
        PermRow("Caméra (photo à distance)", cameraGranted) {
            camLauncher.launch(Manifest.permission.CAMERA)
        }
        PermRow("Administration d'appareil (verrouiller / effacer)", adminActive) {
            val i = Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN)
                .putExtra(DevicePolicyManager.EXTRA_DEVICE_ADMIN, GardienDeviceAdminReceiver.component(context))
                .putExtra(
                    DevicePolicyManager.EXTRA_ADD_EXPLANATION,
                    "Permet de verrouiller ou d'effacer cet appareil à distance depuis le tableau de bord Gardien, en cas de perte ou de vol."
                )
            adminLauncher.launch(i)
        }

        Spacer(Modifier.height(20.dp))
        OutlinedButton(onClick = {
            prefs.clearEnrollment()
            onUnpair()
        }, modifier = Modifier.fillMaxWidth()) {
            Text("Dissocier cet appareil")
        }
        Spacer(Modifier.height(10.dp))
        Text(
            "Pour tout retirer : dissociez ici, désactivez l'administration dans Paramètres › Sécurité, puis désinstallez l'application.",
            fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center
        )
    }
}

/* ---------- petits composants ---------- */

@Composable
private fun SectionCard(content: @Composable ColumnScope.() -> Unit) {
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)
    ) {
        Column(Modifier.padding(18.dp), content = content)
    }
}

@Composable
private fun PermRow(label: String, granted: Boolean, enabled: Boolean = true, onGrant: () -> Unit) {
    Card(
        Modifier.fillMaxWidth().padding(vertical = 4.dp),
        shape = RoundedCornerShape(10.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp)
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(label, Modifier.weight(1f), fontSize = 14.sp)
            if (granted) {
                Text("✓ accordé", color = Color(0xFF2C8F55), fontSize = 13.sp, fontWeight = FontWeight.Medium)
            } else {
                Button(onClick = onGrant, enabled = enabled, shape = RoundedCornerShape(8.dp)) {
                    Text("Autoriser")
                }
            }
        }
    }
}

@Composable
private fun InfoBanner(text: String) {
    Card(
        Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(10.dp),
        colors = CardDefaults.cardColors(containerColor = Color(0xFFE7F3FB))
    ) {
        Text(text, Modifier.padding(14.dp), fontSize = 13.sp, color = BlueDark)
    }
}

private fun hasPerm(context: Context, perm: String): Boolean =
    ContextCompat.checkSelfPermission(context, perm) == PackageManager.PERMISSION_GRANTED

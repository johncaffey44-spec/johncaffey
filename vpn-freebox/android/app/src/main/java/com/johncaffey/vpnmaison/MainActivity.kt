package com.johncaffey.vpnmaison

import android.app.Activity
import android.net.Uri
import android.os.Bundle
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.lifecycleScope
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.zip.ZipInputStream

class MainActivity : ComponentActivity() {

    private val manager get() = tunnels

    /** Fenêtre système « Demande de connexion VPN » (une seule fois). */
    private val vpnPermission = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { r ->
        if (r.resultCode == Activity.RESULT_OK) {
            manager.connect()
        } else {
            manager.reportError(
                "Vous avez refusé la demande « Connexion VPN ». Sans cette autorisation Android ne peut pas créer le tunnel. " +
                    "Appuyez à nouveau sur le bouton et choisissez « OK »."
            )
        }
    }

    private val qrScanner = registerForActivityResult(ScanContract()) { result ->
        result.contents?.let { importText(it) }
    }

    private val filePicker = registerForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        uri?.let { importUri(it) }
    }

    private var importError by mutableStateOf<String?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            VpnTheme {
                val state by manager.state.collectAsStateWithLifecycle()
                AppScreen(
                    state = state,
                    onToggle = ::onToggle,
                    onScanQr = ::scanQr,
                    onPickFile = { filePicker.launch(arrayOf("*/*")) },
                    onPasteText = ::importText,
                    onHomeOnly = manager::setHomeOnly,
                    onDelete = manager::deleteConfig,
                    onDismissError = manager::clearError,
                    onTick = manager::refreshStats,
                )
                importError?.let { msg ->
                    AlertDialog(
                        onDismissRequest = { importError = null },
                        icon = { Icon(Icons.Filled.Warning, null) },
                        title = { Text("Import impossible") },
                        text = { Text(msg) },
                        confirmButton = { TextButton(onClick = { importError = null }) { Text("Compris") } },
                    )
                }
            }
        }
    }

    private fun onToggle() {
        val status = manager.state.value.status
        if (status == VpnStatus.DOWN) {
            val intent = manager.permissionIntent()
            if (intent != null) vpnPermission.launch(intent) else manager.connect()
        } else {
            manager.toggle()
        }
    }

    private fun scanQr() {
        qrScanner.launch(
            ScanOptions()
                .setDesiredBarcodeFormats(ScanOptions.QR_CODE)
                .setPrompt("Visez le QR code WireGuard affiché dans Freebox OS")
                .setBeepEnabled(false)
                .setOrientationLocked(false)
        )
    }

    private fun importText(text: String) {
        lifecycleScope.launch {
            try {
                manager.importConfig(text)
                Toast.makeText(this@MainActivity, "Configuration importée ✔", Toast.LENGTH_SHORT).show()
            } catch (e: Exception) {
                importError = Errors.describe(e)
            }
        }
    }

    /** Fichier .conf (ou .zip contenant un .conf) choisi dans « Fichiers ». */
    private fun importUri(uri: Uri) {
        try {
            val bytes = contentResolver.openInputStream(uri)?.use { it.readBytes() }
                ?: throw InvalidConfigException("Impossible d'ouvrir ce fichier.")
            if (bytes.size > MAX_FILE) throw InvalidConfigException("Ce fichier est trop gros pour être une configuration WireGuard.")
            val text = if (bytes.size > 2 && bytes[0] == 'P'.code.toByte() && bytes[1] == 'K'.code.toByte()) {
                firstConfInZip(bytes) ?: throw InvalidConfigException("Aucun fichier .conf dans cette archive .zip.")
            } else {
                String(bytes, Charsets.UTF_8)
            }
            importText(text)
        } catch (e: Exception) {
            importError = Errors.describe(e)
        }
    }

    private fun firstConfInZip(bytes: ByteArray): String? {
        ZipInputStream(bytes.inputStream()).use { zip ->
            while (true) {
                val entry = zip.nextEntry ?: return null
                if (!entry.isDirectory && entry.name.endsWith(".conf", ignoreCase = true)) {
                    val buf = zip.readBytes()
                    if (buf.size <= MAX_FILE) return String(buf, Charsets.UTF_8)
                }
            }
        }
    }

    private companion object {
        const val MAX_FILE = 64 * 1024
    }
}

// ---------------------------------------------------------------- Thème

private val Blue = Color(0xFF0B3D91)
private val Green = Color(0xFF1E8E3E)
private val Amber = Color(0xFFF29900)
private val Grey = Color(0xFF8A8F98)
private val Red = Color(0xFFC5221F)

@Composable
fun VpnTheme(content: @Composable () -> Unit) {
    val colors = if (isSystemInDarkTheme()) {
        darkColorScheme(primary = Color(0xFF8AB4F8), secondary = Color(0xFF81C995))
    } else {
        lightColorScheme(primary = Blue, secondary = Green)
    }
    MaterialTheme(colorScheme = colors, content = content)
}

// ---------------------------------------------------------------- Écrans

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppScreen(
    state: VpnUiState,
    onToggle: () -> Unit,
    onScanQr: () -> Unit,
    onPickFile: () -> Unit,
    onPasteText: (String) -> Unit,
    onHomeOnly: (Boolean) -> Unit,
    onDelete: () -> Unit,
    onDismissError: () -> Unit,
    onTick: () -> Unit,
) {
    var menuOpen by remember { mutableStateOf(false) }
    var showHelp by rememberSaveable { mutableStateOf(false) }
    var showPaste by rememberSaveable { mutableStateOf(false) }
    var confirmDelete by rememberSaveable { mutableStateOf(false) }
    var showImport by rememberSaveable { mutableStateOf(false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("VPN Maison", fontWeight = FontWeight.Bold) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.surface),
                actions = {
                    IconButton(onClick = { showHelp = true }) { Icon(Icons.Filled.Info, "Aide") }
                    if (state.status != VpnStatus.NO_CONFIG) {
                        IconButton(onClick = { menuOpen = true }) { Icon(Icons.Filled.MoreVert, "Menu") }
                        DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                            DropdownMenuItem(
                                text = { Text("Remplacer la configuration") },
                                onClick = { menuOpen = false; showImport = true },
                            )
                            DropdownMenuItem(
                                text = { Text("Supprimer la configuration", color = Red) },
                                onClick = { menuOpen = false; confirmDelete = true },
                            )
                        }
                    }
                },
            )
        },
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            if (state.status == VpnStatus.NO_CONFIG) {
                WelcomeScreen(onScanQr, onPickFile, { showPaste = true })
            } else {
                ConnectedScreen(state, onToggle, onHomeOnly, onDismissError, onTick)
            }
        }
    }

    if (showImport) {
        AlertDialog(
            onDismissRequest = { showImport = false },
            title = { Text("Nouvelle configuration") },
            text = { Text("La configuration actuelle sera remplacée. Comment voulez-vous importer la nouvelle ?") },
            confirmButton = {
                Column(horizontalAlignment = Alignment.End) {
                    TextButton(onClick = { showImport = false; onScanQr() }) { Text("Scanner le QR code") }
                    TextButton(onClick = { showImport = false; onPickFile() }) { Text("Choisir un fichier .conf") }
                    TextButton(onClick = { showImport = false; showPaste = true }) { Text("Coller le texte") }
                    TextButton(onClick = { showImport = false }) { Text("Annuler") }
                }
            },
        )
    }
    if (showPaste) PasteDialog(onDismiss = { showPaste = false }, onValidate = { showPaste = false; onPasteText(it) })
    if (showHelp) HelpDialog { showHelp = false }
    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            icon = { Icon(Icons.Filled.Warning, null) },
            title = { Text("Supprimer la configuration ?") },
            text = { Text("Le VPN sera coupé et la configuration effacée de ce téléphone. Il faudra la réimporter depuis Freebox OS.") },
            confirmButton = { TextButton(onClick = { confirmDelete = false; onDelete() }) { Text("Supprimer", color = Red) } },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text("Annuler") } },
        )
    }
}

@Composable
private fun WelcomeScreen(onScanQr: () -> Unit, onPickFile: () -> Unit, onPaste: () -> Unit) {
    Spacer(Modifier.height(12.dp))
    Box(
        Modifier
            .size(112.dp)
            .background(Blue, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Icon(painterResource(R.drawable.ic_shield), null, tint = Color.White, modifier = Modifier.size(64.dp))
    }
    Spacer(Modifier.height(20.dp))
    Text("Bienvenue !", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
    Spacer(Modifier.height(8.dp))
    Text(
        "Pour commencer, importez la configuration WireGuard créée dans Freebox OS " +
            "(tutoriel PDF, partie 2). C'est à faire une seule fois.",
        textAlign = TextAlign.Center,
        style = MaterialTheme.typography.bodyLarge,
    )
    Spacer(Modifier.height(24.dp))
    BigButton("Scanner le QR code", R.drawable.ic_qr, onScanQr, primary = true)
    Text(
        "Recommandé : affichez le QR code sur l'écran de l'ordinateur et visez-le avec le téléphone.",
        style = MaterialTheme.typography.bodySmall,
        textAlign = TextAlign.Center,
        modifier = Modifier.padding(top = 6.dp, bottom = 14.dp),
    )
    BigButton("Importer un fichier .conf", R.drawable.ic_file, onPickFile, primary = false)
    Spacer(Modifier.height(12.dp))
    BigButton("Coller le texte de la configuration", R.drawable.ic_paste, onPaste, primary = false)
    Spacer(Modifier.height(24.dp))
    InfoCard(
        icon = Icons.Filled.Lock,
        title = "Vos données restent chez vous",
        text = "La configuration (qui contient votre clé secrète) est rangée uniquement dans ce téléphone. " +
            "Elle n'est envoyée à aucun serveur et n'est jamais sauvegardée dans le cloud.",
    )
}

@Composable
private fun ConnectedScreen(
    state: VpnUiState,
    onToggle: () -> Unit,
    onHomeOnly: (Boolean) -> Unit,
    onDismissError: () -> Unit,
    onTick: () -> Unit,
) {
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(state.status) {
        while (state.status == VpnStatus.UP) {
            onTick()
            now = System.currentTimeMillis()
            delay(1000)
        }
    }

    val busy = state.status == VpnStatus.CONNECTING || state.status == VpnStatus.DISCONNECTING
    val color by animateColorAsState(
        when (state.status) {
            VpnStatus.UP -> Green
            VpnStatus.CONNECTING, VpnStatus.DISCONNECTING -> Amber
            else -> Grey
        },
        label = "couleur",
    )

    Spacer(Modifier.height(16.dp))
    // ---- Gros bouton marche / arrêt
    Surface(
        shape = CircleShape,
        color = color,
        shadowElevation = 8.dp,
        modifier = Modifier
            .size(190.dp)
            .clickable(enabled = !busy, role = Role.Switch, onClickLabel = "Connecter ou déconnecter") { onToggle() },
    ) {
        Box(contentAlignment = Alignment.Center) {
            if (busy) {
                CircularProgressIndicator(color = Color.White, strokeWidth = 5.dp, modifier = Modifier.size(80.dp))
            } else {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(painterResource(R.drawable.ic_power), null, tint = Color.White, modifier = Modifier.size(72.dp))
                    Text(
                        if (state.status == VpnStatus.UP) "Déconnecter" else "Connecter",
                        color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp,
                    )
                }
            }
        }
    }
    Spacer(Modifier.height(16.dp))
    Text(
        when (state.status) {
            VpnStatus.UP -> "Connecté à ma Freebox"
            VpnStatus.CONNECTING -> "Connexion en cours…"
            VpnStatus.DISCONNECTING -> "Déconnexion…"
            else -> "Déconnecté"
        },
        style = MaterialTheme.typography.headlineSmall,
        fontWeight = FontWeight.Bold,
        color = if (state.status == VpnStatus.UP) Green else MaterialTheme.colorScheme.onSurface,
    )

    // ---- Preuve que la Freebox répond (échange de clés WireGuard)
    if (state.status == VpnStatus.UP) {
        val waited = now - state.connectedSince
        val text = when {
            state.lastHandshake > 0 && now - state.lastHandshake < 180_000 ->
                "✔ La Freebox répond (dernier échange ${Format.ago(state.lastHandshake, now)})"
            state.lastHandshake > 0 -> "Liaison en veille (aucun trafic récent) – c'est normal"
            waited < 15_000 -> "En attente de la réponse de la Freebox…"
            else -> null
        }
        if (text != null) Text(text, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 4.dp))
        if (state.lastHandshake == 0L && waited >= 15_000) {
            Spacer(Modifier.height(12.dp))
            WarningCard(
                title = "La Freebox ne répond pas",
                text = "• Testez en 4G/5G (Wi-Fi coupé), pas sur le Wi-Fi de la maison.\n" +
                    "• Vérifiez que le serveur WireGuard est bien « Activé » dans Freebox OS.\n" +
                    "• Ligne en « IPv4 partagée » : gardez le port proposé par Freebox OS, ou demandez l'IP fixe V4 full-stack (tutoriel, partie 1).\n" +
                    "• Si vous avez recréé l'utilisateur dans Freebox OS, réimportez le nouveau QR code.",
            )
        }
    }

    state.error?.let {
        Spacer(Modifier.height(12.dp))
        WarningCard(title = "Problème", text = it, onClose = onDismissError)
    }

    Spacer(Modifier.height(20.dp))
    // ---- Informations
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors()) {
        Column(Modifier.padding(16.dp)) {
            Text("Ma connexion", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))
            val s = state.summary
            InfoRow("Adresse de la box", s?.endpointHost ?: "—")
            InfoRow("Port", s?.endpointPort ?: "—")
            InfoRow("Mon adresse dans le tunnel", s?.addresses?.joinToString() ?: "—")
            if (state.status == VpnStatus.UP) {
                HorizontalDivider(Modifier.padding(vertical = 8.dp))
                InfoRow("Connecté depuis", Format.duration(now - state.connectedSince))
                InfoRow("Reçu", Format.bytes(state.rxBytes))
                InfoRow("Envoyé", Format.bytes(state.txBytes))
            }
        }
    }

    // ---- Mode : tout Internet / maison uniquement
    if (state.summary?.fullTunnel == true || state.homeOnly) {
        Spacer(Modifier.height(12.dp))
        Card(Modifier.fillMaxWidth()) {
            Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Tout Internet passe par ma Freebox", fontWeight = FontWeight.Bold)
                    Text(
                        if (!state.homeOnly) {
                            "Recommandé sur un Wi-Fi public (hôtel, gare, café) : tout est chiffré jusqu'à la maison."
                        } else {
                            "Désactivé : seul l'accès à la maison (box, NAS, imprimante…) passe par le VPN. Le reste va directement sur Internet."
                        },
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                Spacer(Modifier.width(12.dp))
                Switch(
                    checked = !state.homeOnly,
                    enabled = state.homeOnlyAvailable && !busy,
                    onCheckedChange = { onHomeOnly(!it) },
                )
            }
        }
    }

    // ---- Test : la box répond-elle à travers le tunnel ?
    if (state.status == VpnStatus.UP) {
        Spacer(Modifier.height(12.dp))
        FreeboxTestCard()
    }
    Spacer(Modifier.height(24.dp))
}

@Composable
private fun FreeboxTestCard() {
    val scope = rememberCoroutineScope()
    var running by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<FreeboxCheck.Result?>(null) }
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            Text("Tester l'accès à ma Freebox", fontWeight = FontWeight.Bold)
            Text(
                "Pour un vrai test, coupez le Wi-Fi du téléphone (4G/5G seulement).",
                style = MaterialTheme.typography.bodySmall,
            )
            Spacer(Modifier.height(8.dp))
            OutlinedButton(
                enabled = !running,
                onClick = {
                    running = true
                    result = null
                    scope.launch {
                        result = FreeboxCheck.run()
                        running = false
                    }
                },
            ) { Text(if (running) "Test en cours…" else "Lancer le test") }
            when (val r = result) {
                is FreeboxCheck.Result.Ok -> Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.CheckCircle, null, tint = Green)
                    Spacer(Modifier.width(8.dp))
                    Text("Bravo ! Votre box répond : ${r.model}", color = Green, fontWeight = FontWeight.Bold)
                }
                FreeboxCheck.Result.Unreachable -> Text(
                    "La box ne répond pas à travers le VPN. Si l'option « Tout Internet passe par ma Freebox » est désactivée, " +
                        "réactivez-la et refaites le test.",
                    color = Red,
                    modifier = Modifier.padding(top = 8.dp),
                )
                null -> Unit
            }
        }
    }
}

// ---------------------------------------------------------------- Petits composants

@Composable
private fun BigButton(label: String, icon: Int, onClick: () -> Unit, primary: Boolean) {
    val mod = Modifier
        .fillMaxWidth()
        .heightIn(min = 60.dp)
    val content: @Composable () -> Unit = {
        Icon(painterResource(icon), null, modifier = Modifier.size(26.dp))
        Spacer(Modifier.width(12.dp))
        Text(label, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
    }
    if (primary) {
        Button(onClick = onClick, modifier = mod, shape = RoundedCornerShape(14.dp)) { content() }
    } else {
        OutlinedButton(
            onClick = onClick, modifier = mod, shape = RoundedCornerShape(14.dp),
            border = BorderStroke(2.dp, MaterialTheme.colorScheme.primary),
        ) { content() }
    }
}

@Composable
private fun InfoRow(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(label, Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
        Text(value, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.bodyMedium, textAlign = TextAlign.End)
    }
}

@Composable
private fun InfoCard(icon: ImageVector, title: String, text: String) {
    Card(Modifier.fillMaxWidth()) {
        Row(Modifier.padding(16.dp)) {
            Icon(icon, null, tint = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.width(12.dp))
            Column {
                Text(title, fontWeight = FontWeight.Bold)
                Text(text, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun WarningCard(title: String, text: String, onClose: (() -> Unit)? = null) {
    Card(
        Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = Color(0x22F29900)),
        border = BorderStroke(2.dp, Amber),
    ) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Warning, null, tint = Amber)
                Spacer(Modifier.width(8.dp))
                Text(title, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                if (onClose != null) TextButton(onClick = onClose) { Text("OK") }
            }
            Text(text, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun PasteDialog(onDismiss: () -> Unit, onValidate: (String) -> Unit) {
    var text by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Coller la configuration") },
        text = {
            Column {
                Text("Collez tout le texte, de [Interface] jusqu'à la fin.", style = MaterialTheme.typography.bodySmall)
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = text,
                    onValueChange = { text = it },
                    modifier = Modifier.fillMaxWidth().heightIn(min = 160.dp),
                    textStyle = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, autoCorrectEnabled = false),
                    placeholder = { Text("[Interface]\nPrivateKey = …") },
                )
            }
        },
        confirmButton = { TextButton(enabled = text.isNotBlank(), onClick = { onValidate(text) }) { Text("Importer") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Annuler") } },
    )
}

@Composable
private fun HelpDialog(onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Aide rapide") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Text(
                    "1. Sur l'ordinateur : Freebox OS > Paramètres de la Freebox > Mode avancé > Serveur VPN > WireGuard : cocher « Activer ».\n\n" +
                        "2. Onglet « Utilisateurs » > Ajouter : type WireGuard, un nom (ex. « telephone-jean »).\n\n" +
                        "3. Cliquer sur l'icône QR code de cet utilisateur, puis ici « Scanner le QR code ».\n\n" +
                        "4. Appuyer sur le gros bouton et accepter la demande « Connexion VPN » d'Android.\n\n" +
                        "5. Test : couper le Wi-Fi, rester en 4G, puis « Lancer le test ».\n\n" +
                        "Astuce : ajoutez la tuile « VPN Maison » dans les réglages rapides (glisser deux fois vers le bas, crayon ✎) " +
                        "pour vous connecter sans ouvrir l'application.\n\n" +
                        "Sécurité : une configuration = un appareil. Téléphone perdu ? Supprimez son utilisateur dans Freebox OS.",
                    style = MaterialTheme.typography.bodyMedium,
                )
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Fermer") } },
    )
}

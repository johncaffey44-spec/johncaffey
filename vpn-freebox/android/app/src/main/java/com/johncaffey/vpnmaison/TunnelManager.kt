package com.johncaffey.vpnmaison

import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.util.AtomicFile
import com.wireguard.android.backend.GoBackend
import com.wireguard.android.backend.Tunnel
import com.wireguard.config.Config
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import java.io.File
import java.io.FileNotFoundException

enum class VpnStatus { NO_CONFIG, DOWN, CONNECTING, UP, DISCONNECTING }

data class VpnUiState(
    val status: VpnStatus = VpnStatus.NO_CONFIG,
    val summary: WgText.Summary? = null,
    /** Vrai = seul l'accès à la maison passe par le VPN (pas tout Internet). */
    val homeOnly: Boolean = false,
    /** Le mode « maison uniquement » est-il possible avec cette configuration ? */
    val homeOnlyAvailable: Boolean = false,
    val connectedSince: Long = 0L,
    val rxBytes: Long = 0L,
    val txBytes: Long = 0L,
    /** Dernier échange de clés avec la Freebox (0 = jamais) : preuve que la box répond. */
    val lastHandshake: Long = 0L,
    val error: String? = null,
)

/**
 * Cœur de l'application : garde la configuration, allume / éteint le tunnel
 * WireGuard (moteur officiel « GoBackend ») et publie l'état pour l'écran,
 * la tuile des réglages rapides et le « VPN permanent » d'Android.
 */
class TunnelManager(context: Context) {

    private val app = context.applicationContext
    private val backend: GoBackend by lazy { GoBackend(app) }
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val mutex = Mutex()
    private val configFile = AtomicFile(File(app.filesDir, CONFIG_FILE))
    private val prefs = app.getSharedPreferences("reglages", Context.MODE_PRIVATE)

    private val _state = MutableStateFlow(VpnUiState())
    val state: StateFlow<VpnUiState> = _state.asStateFlow()

    private val tunnel = object : Tunnel {
        override fun getName() = TUNNEL_NAME
        override fun onStateChange(newState: Tunnel.State) {
            _state.update { s ->
                if (newState == Tunnel.State.UP) {
                    s.copy(
                        status = VpnStatus.UP,
                        connectedSince = if (s.status == VpnStatus.UP) s.connectedSince else System.currentTimeMillis(),
                    )
                } else {
                    s.copy(
                        status = if (s.summary != null) VpnStatus.DOWN else VpnStatus.NO_CONFIG,
                        connectedSince = 0L, rxBytes = 0L, txBytes = 0L, lastHandshake = 0L,
                    )
                }
            }
        }
    }

    init {
        loadFromDisk()
    }

    private fun loadFromDisk() {
        val text = readConfig()
        val summary = text?.let { WgText.summary(it) }
        val homeOnly = prefs.getBoolean(PREF_HOME_ONLY, false)
        _state.update {
            it.copy(
                status = if (summary == null) VpnStatus.NO_CONFIG else VpnStatus.DOWN,
                summary = summary,
                homeOnly = homeOnly,
                homeOnlyAvailable = text?.let { t -> WgText.homeOnly(t) != null } ?: false,
            )
        }
    }

    private fun readConfig(): String? = try {
        String(configFile.readFully(), Charsets.UTF_8)
    } catch (_: FileNotFoundException) {
        null
    }

    /** Intent système à afficher si Android n'a pas encore autorisé ce VPN, sinon null. */
    fun permissionIntent(): Intent? = VpnService.prepare(app)

    /**
     * Vérifie puis enregistre une configuration (texte du QR code ou du fichier .conf).
     * @throws Exception message lisible via [Errors.describe]
     */
    suspend fun importConfig(raw: String) = withContext(Dispatchers.IO) {
        val text = raw.replace("\r\n", "\n").trim() + "\n"
        if (!WgText.looksLikeWireGuard(text)) {
            throw InvalidConfigException(
                "Ce QR code / fichier n'est pas une configuration WireGuard. " +
                    "Dans Freebox OS, utilisez l'icône QR code (ou la disquette) de l'utilisateur WireGuard."
            )
        }
        // Validation complète par la bibliothèque officielle (clés, adresses, ports).
        Config.parse(text.byteInputStream())
        if (WgText.summary(text).peerCount != 1) {
            throw InvalidConfigException("La configuration doit contenir un seul serveur [Peer] (votre Freebox).")
        }
        mutex.withLock {
            val out = configFile.startWrite()
            try {
                out.write(text.toByteArray(Charsets.UTF_8))
                configFile.finishWrite(out)
            } catch (e: Exception) {
                configFile.failWrite(out)
                throw e
            }
            val wasUp = _state.value.status == VpnStatus.UP
            loadFromDisk()
            _state.update { it.copy(error = null, status = if (wasUp) VpnStatus.UP else it.status) }
            if (wasUp) bringUpLocked() // on recharge le tunnel avec la nouvelle configuration
        }
    }

    fun deleteConfig() = scope.launch {
        mutex.withLock {
            runCatching { backend.setState(tunnel, Tunnel.State.DOWN, null) }
            configFile.delete()
            prefs.edit().remove(PREF_HOME_ONLY).apply()
            _state.value = VpnUiState()
        }
    }

    fun setHomeOnly(enabled: Boolean) = scope.launch {
        mutex.withLock {
            prefs.edit().putBoolean(PREF_HOME_ONLY, enabled).apply()
            _state.update { it.copy(homeOnly = enabled) }
            if (_state.value.status == VpnStatus.UP) bringUpLocked()
        }
    }

    fun connect() = scope.launch { mutex.withLock { bringUpLocked() } }

    fun disconnect() = scope.launch {
        mutex.withLock {
            _state.update { it.copy(status = VpnStatus.DISCONNECTING, error = null) }
            try {
                backend.setState(tunnel, Tunnel.State.DOWN, null)
            } catch (e: Exception) {
                _state.update { it.copy(error = Errors.describe(e)) }
            }
            tunnel.onStateChange(Tunnel.State.DOWN)
        }
    }

    fun toggle() {
        when (_state.value.status) {
            VpnStatus.UP, VpnStatus.CONNECTING -> disconnect()
            VpnStatus.DOWN -> connect()
            else -> Unit
        }
    }

    fun reportError(message: String) = _state.update { it.copy(error = message) }

    fun clearError() = _state.update { it.copy(error = null) }

    private fun bringUpLocked() {
        val text = readConfig() ?: run {
            _state.update { it.copy(status = VpnStatus.NO_CONFIG) }
            return
        }
        val wasUp = _state.value.status == VpnStatus.UP
        _state.update { it.copy(status = VpnStatus.CONNECTING, error = null) }
        try {
            val effective = if (_state.value.homeOnly) WgText.homeOnly(text) ?: text else text
            val config = Config.parse(effective.byteInputStream())
            backend.setState(tunnel, Tunnel.State.UP, config)
            _state.update {
                it.copy(
                    status = VpnStatus.UP,
                    connectedSince = if (wasUp && it.connectedSince != 0L) it.connectedSince else System.currentTimeMillis(),
                    lastHandshake = 0L,
                )
            }
        } catch (e: Exception) {
            tunnel.onStateChange(Tunnel.State.DOWN)
            _state.update { it.copy(error = Errors.describe(e)) }
        }
    }

    /** Met à jour les compteurs (appelé chaque seconde pendant que l'écran est ouvert). */
    fun refreshStats() {
        if (_state.value.status != VpnStatus.UP) return
        scope.launch { readStats() }
    }

    private fun readStats() {
        if (_state.value.status != VpnStatus.UP) return
        try {
            if (backend.getState(tunnel) != Tunnel.State.UP) {
                tunnel.onStateChange(Tunnel.State.DOWN)
                return
            }
            val stats = backend.getStatistics(tunnel)
            val handshake = stats.peers().maxOfOrNull { stats.peer(it)?.latestHandshakeEpochMillis() ?: 0L } ?: 0L
            _state.update { it.copy(rxBytes = stats.totalRx(), txBytes = stats.totalTx(), lastHandshake = handshake) }
        } catch (_: Exception) {
            // statistiques indisponibles un court instant : sans gravité
        }
    }

    companion object {
        const val TUNNEL_NAME = "freebox"
        private const val CONFIG_FILE = "freebox.conf"
        private const val PREF_HOME_ONLY = "maison_uniquement"
    }
}

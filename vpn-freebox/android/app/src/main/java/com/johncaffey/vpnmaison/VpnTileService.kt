package com.johncaffey.vpnmaison

import android.annotation.SuppressLint
import android.app.PendingIntent
import android.content.Intent
import android.os.Build
import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

/** Bouton « VPN Maison » dans le volet des réglages rapides. */
class VpnTileService : TileService() {
    private var job: Job? = null

    override fun onStartListening() {
        super.onStartListening()
        job = CoroutineScope(Dispatchers.Main).launch {
            tunnels.state.collect { render(it) }
        }
    }

    override fun onStopListening() {
        job?.cancel()
        job = null
        super.onStopListening()
    }

    override fun onClick() {
        super.onClick()
        val s = tunnels.state.value
        if (s.status == VpnStatus.NO_CONFIG || tunnels.permissionIntent() != null) {
            openApp() // il faut d'abord importer la configuration ou accepter la demande VPN
            return
        }
        tunnels.toggle()
    }

    @SuppressLint("StartActivityAndCollapseDeprecated")
    private fun openApp() {
        val intent = Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        if (Build.VERSION.SDK_INT >= 34) {
            startActivityAndCollapse(
                PendingIntent.getActivity(this, 0, intent, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
            )
        } else {
            @Suppress("DEPRECATION")
            startActivityAndCollapse(intent)
        }
    }

    private fun render(s: VpnUiState) {
        val tile = qsTile ?: return
        tile.state = if (s.status == VpnStatus.UP) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE
        if (Build.VERSION.SDK_INT >= 29) {
            tile.subtitle = when (s.status) {
                VpnStatus.NO_CONFIG -> "À configurer"
                VpnStatus.DOWN -> "Déconnecté"
                VpnStatus.CONNECTING -> "Connexion…"
                VpnStatus.UP -> "Connecté"
                VpnStatus.DISCONNECTING -> "Déconnexion…"
            }
        }
        tile.updateTile()
    }
}

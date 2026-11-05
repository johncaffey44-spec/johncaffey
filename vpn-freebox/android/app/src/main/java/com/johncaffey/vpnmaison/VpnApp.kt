package com.johncaffey.vpnmaison

import android.app.Application
import com.wireguard.android.backend.GoBackend

class VpnApp : Application() {
    lateinit var tunnels: TunnelManager
        private set

    override fun onCreate() {
        super.onCreate()
        tunnels = TunnelManager(this)
        // « VPN permanent » d'Android (Paramètres > Réseau > VPN > roue dentée) :
        // le système démarre le service tout seul, on rallume alors le tunnel.
        GoBackend.setAlwaysOnCallback { tunnels.connect() }
    }
}

val android.content.Context.tunnels: TunnelManager
    get() = (applicationContext as VpnApp).tunnels

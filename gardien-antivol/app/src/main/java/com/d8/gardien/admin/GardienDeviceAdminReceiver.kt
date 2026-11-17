package com.d8.gardien.admin

import android.app.admin.DeviceAdminReceiver
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context

/**
 * Récepteur d'administration d'appareil. Il donne accès aux API Android LÉGITIMES
 * de verrouillage (lockNow) et d'effacement (wipeData). L'utilisateur l'active
 * explicitement depuis l'écran principal, et peut le désactiver quand il veut dans
 * Paramètres › Sécurité › Applications d'administration de l'appareil.
 */
class GardienDeviceAdminReceiver : DeviceAdminReceiver() {

    override fun onDisabled(context: Context, intent: android.content.Intent) {
        super.onDisabled(context, intent)
        // L'utilisateur a retiré les droits d'administration : verrouillage/effacement
        // à distance ne sont plus possibles tant qu'il ne les réactive pas.
    }

    companion object {
        fun component(context: Context): ComponentName =
            ComponentName(context, GardienDeviceAdminReceiver::class.java)

        fun isActive(context: Context): Boolean {
            val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
            return dpm.isAdminActive(component(context))
        }
    }
}

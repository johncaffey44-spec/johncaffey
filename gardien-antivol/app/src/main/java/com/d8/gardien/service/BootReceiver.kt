package com.d8.gardien.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.d8.gardien.Prefs

/** Relance le service de premier plan après un redémarrage ou une mise à jour de l'application. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action
        if (action == Intent.ACTION_BOOT_COMPLETED || action == Intent.ACTION_MY_PACKAGE_REPLACED || action == Watchdog.ACTION) {
            if (Prefs(context).isEnrolled) {
                runCatching { GardienService.start(context) }
                runCatching { Watchdog.schedule(context) } // ré-arme le filet
            }
        }
    }
}

package com.d8.gardien.service

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.SystemClock

/**
 * Chien de garde : une alarme inexacte toutes les ~15 min qui relance le service de premier plan
 * s'il a été tué par le système (fréquent sur les téléphones utilisés au quotidien, surtout OEM
 * agressifs). Le service de premier plan reste la protection principale ; ceci n'est qu'un filet.
 */
object Watchdog {
    const val ACTION = "com.d8.gardien.WATCHDOG"
    private const val REQ = 4242
    private const val INTERVAL = 15 * 60 * 1000L // 15 minutes

    fun schedule(context: Context) {
        val am = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
        runCatching {
            am.setInexactRepeating(
                AlarmManager.ELAPSED_REALTIME_WAKEUP,
                SystemClock.elapsedRealtime() + INTERVAL,
                INTERVAL,
                pending(context)
            )
        }
    }

    fun cancel(context: Context) {
        val am = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
        runCatching { am.cancel(pending(context)) }
    }

    private fun pending(context: Context): PendingIntent {
        val i = Intent(context, BootReceiver::class.java).setAction(ACTION)
        return PendingIntent.getBroadcast(
            context, REQ, i,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }
}

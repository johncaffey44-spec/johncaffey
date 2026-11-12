package com.d8.gardien.service

import android.app.Notification
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.d8.gardien.GardienApp
import com.d8.gardien.MainActivity
import com.d8.gardien.R
import com.d8.gardien.ui.MessageActivity

/** Construit les notifications. La notification permanente rend Gardien VISIBLE en continu. */
object Notifier {
    const val ONGOING_ID = 1001
    private var actionId = 2000

    private fun openAppIntent(context: Context): PendingIntent {
        val i = Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        return PendingIntent.getActivity(context, 0, i, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
    }

    /** Notification permanente du service de premier plan. Non masquable : Gardien reste visible. */
    fun ongoing(context: Context): Notification =
        NotificationCompat.Builder(context, GardienApp.CH_RUNNING)
            .setSmallIcon(R.drawable.ic_stat_gardien)
            .setContentTitle("Gardien — protection active")
            .setContentText("Cet appareil est suivi par le tableau de bord Gardien.")
            .setContentIntent(openAppIntent(context))
            .setOngoing(true)
            .setShowWhen(false)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()

    /** Notification d'action ponctuelle (photo prise, localisation envoyée…). Visible par conception. */
    fun action(context: Context, title: String, text: String) {
        if (!NotificationManagerCompat.from(context).areNotificationsEnabled()) return
        val n = NotificationCompat.Builder(context, GardienApp.CH_ACTIONS)
            .setSmallIcon(R.drawable.ic_stat_gardien)
            .setContentTitle(title)
            .setContentText(text)
            .setContentIntent(openAppIntent(context))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()
        NotificationManagerCompat.from(context).notify(actionId++, n)
    }

    /** Message plein écran par-dessus le verrouillage, via une notification à intention plein écran. */
    fun fullScreenMessage(context: Context, text: String) {
        val i = Intent(context, MessageActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra(MessageActivity.EXTRA_TEXT, text)
        val pi = PendingIntent.getActivity(
            context, 1, i, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val n = NotificationCompat.Builder(context, GardienApp.CH_MESSAGE)
            .setSmallIcon(R.drawable.ic_stat_gardien)
            .setContentTitle("Message")
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setFullScreenIntent(pi, true)
            .setAutoCancel(true)
            .build()
        NotificationManagerCompat.from(context).notify(actionId++, n)
        // Tentative d'ouverture directe (fonctionne quand l'app est déjà au premier plan).
        runCatching { context.startActivity(i) }
    }
}

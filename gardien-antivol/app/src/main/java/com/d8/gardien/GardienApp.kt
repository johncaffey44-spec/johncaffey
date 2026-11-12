package com.d8.gardien

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build

class GardienApp : Application() {

    override fun onCreate() {
        super.onCreate()
        createChannels()
    }

    private fun createChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = getSystemService(NotificationManager::class.java)

        // Canal de la notification PERMANENTE du service. Volontairement non masquable :
        // Gardien reste visible en permanence. Ne pas passer en IMPORTANCE_MIN serait trompeur,
        // mais IMPORTANCE_LOW garde la notification visible sans son ni interruption.
        val running = NotificationChannel(
            CH_RUNNING, "Protection active", NotificationManager.IMPORTANCE_LOW
        ).apply {
            description = "Notification permanente indiquant que Gardien protège l'appareil."
            setShowBadge(false)
        }

        // Canal des actions à distance (photo prise, localisation demandée…). Visible par conception.
        val actions = NotificationChannel(
            CH_ACTIONS, "Actions à distance", NotificationManager.IMPORTANCE_DEFAULT
        ).apply {
            description = "Informe l'utilisateur de chaque action déclenchée à distance (photo, verrouillage…)."
        }

        // Canal des messages plein écran (intention plein écran). Importance haute pour s'afficher aussitôt.
        val message = NotificationChannel(
            CH_MESSAGE, "Messages", NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Messages affichés en plein écran par l'administrateur."
        }

        nm.createNotificationChannel(running)
        nm.createNotificationChannel(actions)
        nm.createNotificationChannel(message)
    }

    companion object {
        const val CH_RUNNING = "gardien_running"
        const val CH_ACTIONS = "gardien_actions"
        const val CH_MESSAGE = "gardien_message"
    }
}

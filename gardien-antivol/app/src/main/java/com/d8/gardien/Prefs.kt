package com.d8.gardien

import android.content.Context

/**
 * Réglages persistants de l'appareil : adresse du serveur, identifiants d'appairage.
 * Stockés en SharedPreferences privées à l'application.
 */
class Prefs(context: Context) {
    private val sp = context.applicationContext.getSharedPreferences("gardien", Context.MODE_PRIVATE)

    var serverUrl: String
        get() = sp.getString("serverUrl", "") ?: ""
        set(v) = sp.edit().putString("serverUrl", v).apply()

    var deviceId: String
        get() = sp.getString("deviceId", "") ?: ""
        set(v) = sp.edit().putString("deviceId", v).apply()

    var deviceToken: String
        get() = sp.getString("deviceToken", "") ?: ""
        set(v) = sp.edit().putString("deviceToken", v).apply()

    var deviceName: String
        get() = sp.getString("deviceName", "") ?: ""
        set(v) = sp.edit().putString("deviceName", v).apply()

    /** Intervalle de relève conseillé par le serveur (secondes). */
    var heartbeat: Int
        get() = sp.getInt("heartbeat", 20)
        set(v) = sp.edit().putInt("heartbeat", v).apply()

    val isEnrolled: Boolean
        get() = serverUrl.isNotEmpty() && deviceId.isNotEmpty() && deviceToken.isNotEmpty()

    fun clearEnrollment() {
        sp.edit().remove("deviceId").remove("deviceToken").apply()
    }
}

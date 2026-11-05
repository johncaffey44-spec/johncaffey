package com.johncaffey.vpnmaison

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/**
 * « Ma Freebox répond-elle ? » : interroge la petite page publique
 * /api_version de Freebox OS. Elle ne renvoie que le modèle de la box,
 * aucun identifiant ni mot de passe n'est envoyé.
 */
object FreeboxCheck {
    sealed interface Result {
        data class Ok(val model: String) : Result
        data object Unreachable : Result
    }

    private val urls = listOf(
        "http://mafreebox.freebox.fr/api_version",
        "http://192.168.1.254/api_version",
    )

    suspend fun run(): Result = withContext(Dispatchers.IO) {
        for (u in urls) {
            try {
                val c = URL(u).openConnection() as HttpURLConnection
                c.connectTimeout = 5000
                c.readTimeout = 5000
                c.instanceFollowRedirects = false
                c.useCaches = false
                try {
                    if (c.responseCode == 200) {
                        val body = c.inputStream.bufferedReader().use { it.readText().take(8192) }
                        val json = JSONObject(body)
                        val model = json.optString("box_model_name").ifBlank { json.optString("device_name") }
                        if (model.isNotBlank()) return@withContext Result.Ok(model)
                    }
                } finally {
                    c.disconnect()
                }
            } catch (_: Exception) {
                // on essaie l'adresse suivante
            }
        }
        Result.Unreachable
    }
}

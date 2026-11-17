package com.d8.gardien.net

import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/**
 * Client de l'API Gardien : POST JSON vers gardien-api.php.
 * Appels BLOQUANTS — à invoquer depuis un thread d'arrière-plan (Dispatchers.IO).
 * Aucune dépendance tierce : HttpURLConnection + org.json.
 */
object Api {

    class ApiException(message: String, val status: Int) : Exception(message)

    data class EnrollResult(val deviceId: String, val deviceToken: String, val heartbeat: Int)
    data class Command(val id: String, val type: String, val params: JSONObject)
    data class HeartbeatResult(val commands: List<Command>, val heartbeat: Int)

    private fun post(serverUrl: String, body: JSONObject): JSONObject {
        val conn = (URL(serverUrl).openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 12000
            readTimeout = 15000
            doOutput = true
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("Accept", "application/json")
        }
        try {
            conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else (conn.errorStream ?: conn.inputStream)
            val text = stream.bufferedReader().use { it.readText() }
            val json = if (text.isBlank()) JSONObject() else JSONObject(text)
            if (code !in 200..299) {
                throw ApiException(json.optString("error", "Erreur $code"), code)
            }
            return json
        } finally {
            conn.disconnect()
        }
    }

    /** Appaire l'appareil avec un code à usage unique. */
    fun enroll(serverUrl: String, code: String, name: String, model: String): EnrollResult {
        val r = post(serverUrl, JSONObject().apply {
            put("action", "enroll")
            put("code", code)
            put("name", name)
            put("model", model)
        })
        return EnrollResult(
            deviceId = r.getString("deviceId"),
            deviceToken = r.getString("deviceToken"),
            heartbeat = r.optInt("heartbeat", 20)
        )
    }

    /**
     * Relève : pousse l'état (batterie, position) et les résultats des commandes exécutées,
     * récupère les commandes en attente.
     */
    fun heartbeat(
        serverUrl: String,
        deviceId: String,
        deviceToken: String,
        battery: Int?,
        charging: Boolean?,
        location: JSONObject?,
        results: JSONArray
    ): HeartbeatResult {
        val body = JSONObject().apply {
            put("action", "heartbeat")
            put("deviceId", deviceId)
            put("deviceToken", deviceToken)
            battery?.let { put("battery", it) }
            charging?.let { put("charging", it) }
            location?.let { put("location", it) }
            if (results.length() > 0) put("results", results)
        }
        val r = post(serverUrl, body)
        val arr = r.optJSONArray("commands") ?: JSONArray()
        val cmds = ArrayList<Command>(arr.length())
        for (i in 0 until arr.length()) {
            val c = arr.getJSONObject(i)
            cmds.add(Command(c.getString("id"), c.getString("type"), c.optJSONObject("params") ?: JSONObject()))
        }
        return HeartbeatResult(cmds, r.optInt("heartbeat", 20))
    }

    /** Envoie une photo (JPEG encodé en base64) au serveur, en réponse à une commande « photo ». */
    fun uploadPhoto(serverUrl: String, deviceId: String, deviceToken: String, commandId: String, camera: String, jpegBase64: String): Boolean {
        val r = post(serverUrl, JSONObject().apply {
            put("action", "upload-photo")
            put("deviceId", deviceId)
            put("deviceToken", deviceToken)
            put("commandId", commandId)
            put("camera", camera)
            put("image", jpegBase64)
        })
        return r.optBoolean("ok", false)
    }
}

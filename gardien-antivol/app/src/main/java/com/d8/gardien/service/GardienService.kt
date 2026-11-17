package com.d8.gardien.service

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.Build
import android.os.IBinder
import android.util.Base64
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.LifecycleService
import androidx.lifecycle.lifecycleScope
import com.d8.gardien.Prefs
import com.d8.gardien.cmd.LocationOnce
import com.d8.gardien.cmd.Lock
import com.d8.gardien.cmd.PhotoCapture
import com.d8.gardien.cmd.Ring
import com.d8.gardien.cmd.Wipe
import com.d8.gardien.net.Api
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject

/**
 * Service de premier plan VISIBLE (notification permanente). Relève périodiquement
 * le serveur : pousse batterie + position, récupère et exécute les commandes.
 * Volontairement sans mode caché : la notification ne peut pas être supprimée tant
 * que le service tourne.
 */
class GardienService : LifecycleService() {

    private var loopJob: Job? = null
    private val results = ArrayList<JSONObject>()           // résultats à transmettre à la prochaine relève
    @Volatile private var freshLoc: JSONObject? = null       // position fraîche issue d'une commande « locate »

    override fun onCreate() {
        super.onCreate()
        startForegroundVisible()
        Watchdog.schedule(this) // filet : relance le service s'il est tué (téléphones OEM agressifs)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        super.onStartCommand(intent, flags, startId)
        startLoop()
        return START_STICKY
    }

    override fun onBind(intent: Intent): IBinder? {
        super.onBind(intent)
        return null
    }

    private fun fgsType(camera: Boolean): Int {
        val hasLoc =
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        var t = ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
        if (hasLoc) t = t or ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
        if (camera && ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED)
            t = t or ServiceInfo.FOREGROUND_SERVICE_TYPE_CAMERA
        return t
    }

    private fun startForegroundVisible() {
        val notif = Notifier.ongoing(this)
        // Android 12+ interdit de démarrer un service de type « location » depuis l'arrière-plan
        // (ex. au démarrage de l'appareil) : on retombe alors sur « dataSync ».
        val ok = runCatching { ServiceCompat.startForeground(this, Notifier.ONGOING_ID, notif, fgsType(false)) }.isSuccess
        if (!ok) runCatching { ServiceCompat.startForeground(this, Notifier.ONGOING_ID, notif, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC) }
    }

    /** Ajoute (ou retire) temporairement le type « caméra » au service — requis sur Android 14 pour photographier. */
    private fun elevateCamera(on: Boolean) {
        runCatching { ServiceCompat.startForeground(this, Notifier.ONGOING_ID, Notifier.ongoing(this), fgsType(on)) }
    }

    private fun startLoop() {
        if (loopJob?.isActive == true) return
        loopJob = lifecycleScope.launch(Dispatchers.IO) {
            var backoff = 5000L
            while (isActive) {
                val prefs = Prefs(this@GardienService)
                if (!prefs.isEnrolled) { delay(5000); continue }
                try {
                    val pending = drainResults()
                    val location = freshLoc ?: lastKnownJson()
                    val r = Api.heartbeat(
                        serverUrl = prefs.serverUrl,
                        deviceId = prefs.deviceId,
                        deviceToken = prefs.deviceToken,
                        battery = Battery.level(this@GardienService),
                        charging = Battery.charging(this@GardienService),
                        location = location,
                        results = pending
                    )
                    freshLoc = null
                    prefs.heartbeat = r.heartbeat
                    for (cmd in r.commands) handleCommand(cmd)
                    backoff = 5000L
                    delay(r.heartbeat.coerceIn(5, 300) * 1000L)
                } catch (e: Api.ApiException) {
                    // 401 = jeton invalide (appareil retiré du tableau de bord) : on patiente, l'utilisateur ré-appairera.
                    delay(backoff.also { backoff = (backoff * 2).coerceAtMost(120_000L) })
                } catch (e: Exception) {
                    // réseau indisponible : on réessaie avec un délai croissant
                    delay(backoff.also { backoff = (backoff * 2).coerceAtMost(120_000L) })
                }
            }
        }
    }

    private suspend fun handleCommand(cmd: Api.Command) {
        val ctx = this@GardienService
        when (cmd.type) {
            "locate" -> {
                val loc = LocationOnce.get(ctx)
                if (loc != null) {
                    freshLoc = loc.toJson()
                    result(cmd.id, true, "Position relevée")
                } else {
                    result(cmd.id, false, "Position indisponible (GPS ou permission)")
                }
                Notifier.action(ctx, "Localisation", "Position envoyée au tableau de bord Gardien.")
            }
            "ring" -> {
                Ring.play(ctx, cmd.params.optInt("seconds", 30))
                result(cmd.id, true, "Alarme déclenchée")
                Notifier.action(ctx, "Sonnerie", "Gardien fait sonner cet appareil.")
            }
            "lock" -> {
                val ok = Lock.now(ctx)
                result(cmd.id, ok, if (ok) "Appareil verrouillé" else "Administration d'appareil non activée")
            }
            "message" -> {
                val text = cmd.params.optString("text").ifBlank { "Message de l'administrateur." }
                Notifier.fullScreenMessage(ctx, text)
                result(cmd.id, true, "Message affiché")
            }
            "photo" -> {
                val which = cmd.params.optString("camera", "front").ifBlank { "front" }
                if (ContextCompat.checkSelfPermission(ctx, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                    Notifier.action(ctx, "Photo", "Permission caméra non accordée sur cet appareil.")
                    result(cmd.id, false, "Permission caméra non accordée")
                } else {
                    // VISIBLE par conception : notification AVANT la capture.
                    Notifier.action(ctx, "Photo", "Gardien prend une photo (caméra $which).")
                    withContext(Dispatchers.Main) { elevateCamera(true) }
                    val bytes = PhotoCapture.capture(ctx, which)
                    withContext(Dispatchers.Main) { elevateCamera(false) }
                    if (bytes == null) {
                        result(cmd.id, false, "Échec de la capture photo")
                    } else {
                        val prefs = Prefs(ctx)
                        val b64 = Base64.encodeToString(bytes, Base64.NO_WRAP)
                        val ok = runCatching {
                            Api.uploadPhoto(prefs.serverUrl, prefs.deviceId, prefs.deviceToken, cmd.id, which, b64)
                        }.getOrDefault(false)
                        // si ok : le serveur a déjà marqué la commande « done » via upload-photo
                        if (!ok) result(cmd.id, false, "Photo capturée, envoi échoué")
                    }
                }
            }
            "wipe" -> {
                val external = cmd.params.optString("scope") == "external"
                val ok = Wipe.factoryReset(ctx, external)
                result(cmd.id, ok, if (ok) "Effacement d'usine lancé" else "Administration d'appareil non activée")
            }
            else -> result(cmd.id, false, "Commande inconnue")
        }
    }

    @Synchronized private fun result(id: String, ok: Boolean, message: String) {
        results.add(JSONObject().apply {
            put("id", id); put("ok", ok); put("message", message)
        })
    }

    @Synchronized private fun drainResults(): JSONArray {
        val arr = JSONArray()
        for (r in results) arr.put(r)
        results.clear()
        return arr
    }

    private fun lastKnownJson(): JSONObject? {
        if (!LocationOnce.hasPermission(this)) return null
        val lm = getSystemService(Context.LOCATION_SERVICE) as? android.location.LocationManager ?: return null
        var best: Location? = null
        for (p in lm.allProviders) {
            val loc = runCatching {
                if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
                    ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
                ) lm.getLastKnownLocation(p) else null
            }.getOrNull() ?: continue
            if (best == null || loc.time > best!!.time) best = loc
        }
        // On n'envoie une position d'historique que si elle a moins de 15 min.
        return best?.takeIf { System.currentTimeMillis() - it.time < 15 * 60_000 }?.toJson()
    }

    override fun onDestroy() {
        loopJob?.cancel()
        super.onDestroy()
    }

    companion object {
        fun start(context: Context) {
            val i = Intent(context, GardienService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) context.startForegroundService(i)
            else context.startService(i)
        }
    }
}

private fun Location.toJson(): JSONObject = JSONObject().apply {
    put("lat", latitude)
    put("lng", longitude)
    if (hasAccuracy()) put("acc", accuracy.toDouble())
}

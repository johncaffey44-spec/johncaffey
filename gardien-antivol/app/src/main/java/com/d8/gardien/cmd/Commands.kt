package com.d8.gardien.cmd

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.VibrationEffect
import android.os.Vibrator
import com.d8.gardien.admin.GardienDeviceAdminReceiver

/** Verrouillage immédiat de l'appareil (API Android légitime, via l'administration d'appareil). */
object Lock {
    fun now(context: Context): Boolean {
        if (!GardienDeviceAdminReceiver.isActive(context)) return false
        val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        return runCatching { dpm.lockNow() }.isSuccess
    }
}

/** Effacement d'usine (irréversible). Exige l'administration d'appareil ET une confirmation côté serveur. */
object Wipe {
    fun factoryReset(context: Context): Boolean {
        if (!GardienDeviceAdminReceiver.isActive(context)) return false
        val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        return runCatching { dpm.wipeData(0) }.isSuccess
    }
}

/** Alarme sonore forte, même en mode silencieux, pendant N secondes. */
object Ring {
    private var player: MediaPlayer? = null
    private val handler = Handler(Looper.getMainLooper())
    private var savedVolume = -1
    private var stopper: Runnable? = null

    @Synchronized
    fun play(context: Context, seconds: Int) {
        stop(context) // repart proprement si déjà en cours
        val am = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
        savedVolume = am.getStreamVolume(AudioManager.STREAM_ALARM)
        am.setStreamVolume(AudioManager.STREAM_ALARM, am.getStreamMaxVolume(AudioManager.STREAM_ALARM), 0)

        val uri = RingtoneManager.getActualDefaultRingtoneUri(context, RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getActualDefaultRingtoneUri(context, RingtoneManager.TYPE_RINGTONE)
            ?: return
        player = MediaPlayer().apply {
            setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ALARM)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
            setDataSource(context, uri)
            isLooping = true
            setOnPreparedListener { start() }
            prepareAsync()
        }
        vibrate(context, seconds)

        stopper = Runnable { stop(context) }.also {
            handler.postDelayed(it, seconds.coerceIn(5, 300) * 1000L)
        }
    }

    @Synchronized
    fun stop(context: Context) {
        stopper?.let { handler.removeCallbacks(it) }; stopper = null
        player?.let { runCatching { if (it.isPlaying) it.stop(); it.release() } }
        player = null
        if (savedVolume >= 0) {
            val am = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
            runCatching { am.setStreamVolume(AudioManager.STREAM_ALARM, savedVolume, 0) }
            savedVolume = -1
        }
        (context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator)?.cancel()
    }

    private fun vibrate(context: Context, seconds: Int) {
        val v = context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator ?: return
        val pattern = longArrayOf(0, 600, 400)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            v.vibrate(VibrationEffect.createWaveform(pattern, 0))
        } else {
            @Suppress("DEPRECATION") v.vibrate(pattern, 0)
        }
        Handler(Looper.getMainLooper()).postDelayed({ v.cancel() }, seconds.coerceIn(5, 300) * 1000L)
    }
}

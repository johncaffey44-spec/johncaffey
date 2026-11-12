package com.d8.gardien.cmd

import android.content.Context
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageCapture
import androidx.camera.core.ImageCaptureException
import androidx.camera.core.ImageProxy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import java.util.concurrent.Executors
import kotlin.coroutines.resume

/**
 * Capture UNE photo JPEG via CameraX, sans aperçu (headless) et sans Google Play Services.
 * Renvoie les octets JPEG, ou null (permission manquante, pas de caméra, délai dépassé).
 * À déclencher AVEC une notification visible : Gardien ne photographie jamais en cachette.
 */
object PhotoCapture {

    suspend fun capture(context: Context, which: String, timeoutMs: Long = 15_000): ByteArray? =
        withTimeoutOrNull(timeoutMs) {
            val app = context.applicationContext
            val provider = awaitProvider(app) ?: return@withTimeoutOrNull null
            val owner = TempLifecycleOwner()
            try {
                withContext(Dispatchers.Main) { owner.start() }
                val wanted = if (which == "back") CameraSelector.DEFAULT_BACK_CAMERA else CameraSelector.DEFAULT_FRONT_CAMERA
                val selector = when {
                    runCatching { provider.hasCamera(wanted) }.getOrDefault(false) -> wanted
                    runCatching { provider.hasCamera(CameraSelector.DEFAULT_BACK_CAMERA) }.getOrDefault(false) -> CameraSelector.DEFAULT_BACK_CAMERA
                    else -> CameraSelector.DEFAULT_FRONT_CAMERA
                }
                val capture = ImageCapture.Builder()
                    .setCaptureMode(ImageCapture.CAPTURE_MODE_MINIMIZE_LATENCY)
                    .build()
                withContext(Dispatchers.Main) {
                    provider.unbindAll()
                    provider.bindToLifecycle(owner, selector, capture)
                }
                val exec = Executors.newSingleThreadExecutor()
                val bytes = suspendCancellableCoroutine<ByteArray?> { cont ->
                    capture.takePicture(exec, object : ImageCapture.OnImageCapturedCallback() {
                        override fun onCaptureSuccess(image: ImageProxy) {
                            try {
                                val buf = image.planes[0].buffer
                                val arr = ByteArray(buf.remaining()); buf.get(arr)
                                if (cont.isActive) cont.resume(arr)
                            } catch (e: Exception) {
                                if (cont.isActive) cont.resume(null)
                            } finally { image.close() }
                        }
                        override fun onError(exc: ImageCaptureException) { if (cont.isActive) cont.resume(null) }
                    })
                }
                exec.shutdown()
                bytes
            } catch (e: Exception) {
                null
            } finally {
                withContext(Dispatchers.Main) { runCatching { provider.unbindAll() }; owner.destroy() }
            }
        }

    private suspend fun awaitProvider(ctx: Context): ProcessCameraProvider? =
        suspendCancellableCoroutine { cont ->
            val future = ProcessCameraProvider.getInstance(ctx)
            future.addListener({
                try { cont.resume(future.get()) } catch (e: Exception) { cont.resume(null) }
            }, ContextCompat.getMainExecutor(ctx))
        }

    /** LifecycleOwner minimal, maintenu en état RESUMED le temps de la capture. */
    private class TempLifecycleOwner : LifecycleOwner {
        private val reg = LifecycleRegistry(this)
        override val lifecycle: Lifecycle get() = reg
        fun start() { reg.currentState = Lifecycle.State.RESUMED }
        fun destroy() { reg.currentState = Lifecycle.State.DESTROYED }
    }
}

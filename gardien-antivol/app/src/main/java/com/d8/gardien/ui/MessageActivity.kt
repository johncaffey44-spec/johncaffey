package com.d8.gardien.ui

import android.app.Activity
import android.graphics.Color
import android.os.Build
import android.os.Bundle
import android.view.Gravity
import android.view.ViewGroup
import android.view.WindowManager
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView

/**
 * Affiche un message en plein écran, par-dessus l'écran de verrouillage, et allume l'écran.
 * Déclenché par la commande « message » du tableau de bord.
 */
class MessageActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
            )
        }
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        val text = intent.getStringExtra(EXTRA_TEXT).orEmpty().ifBlank { "Message de l'administrateur." }

        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setBackgroundColor(Color.parseColor("#0E6DA4"))
            setPadding(dp(28), dp(28), dp(28), dp(28))
        }
        root.addView(TextView(this).apply {
            text = "🛡 Gardien"
            setTextColor(Color.WHITE)
            textSize = 18f
            alpha = 0.85f
            gravity = Gravity.CENTER
        })
        root.addView(TextView(this).apply {
            this.text = text
            setTextColor(Color.WHITE)
            textSize = 26f
            gravity = Gravity.CENTER
            setPadding(0, dp(24), 0, dp(32))
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT
            )
        })
        root.addView(Button(this).apply {
            text = "Fermer"
            setOnClickListener { finish() }
        })
        setContentView(root)
    }

    private fun dp(v: Int): Int = (v * resources.displayMetrics.density).toInt()

    companion object {
        const val EXTRA_TEXT = "text"
    }
}

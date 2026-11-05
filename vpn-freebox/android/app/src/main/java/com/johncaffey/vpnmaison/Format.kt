package com.johncaffey.vpnmaison

import java.util.Locale

object Format {
    private val fr = Locale.FRANCE

    fun bytes(b: Long): String = when {
        b < 1024 -> "$b o"
        b < 1024L * 1024 -> String.format(fr, "%.1f Ko", b / 1024.0)
        b < 1024L * 1024 * 1024 -> String.format(fr, "%.1f Mo", b / (1024.0 * 1024))
        else -> String.format(fr, "%.2f Go", b / (1024.0 * 1024 * 1024))
    }

    /** « il y a 5 s », « il y a 3 min », « il y a 2 h ». */
    fun ago(epochMillis: Long, now: Long): String {
        val s = ((now - epochMillis) / 1000).coerceAtLeast(0)
        return when {
            s < 60 -> "il y a $s s"
            s < 3600 -> "il y a ${s / 60} min"
            else -> "il y a ${s / 3600} h"
        }
    }

    /** Durée « 1 h 05 min » / « 3 min 12 s ». */
    fun duration(millis: Long): String {
        val s = (millis / 1000).coerceAtLeast(0)
        val h = s / 3600
        val m = (s % 3600) / 60
        val sec = s % 60
        return when {
            h > 0 -> String.format(fr, "%d h %02d min", h, m)
            m > 0 -> String.format(fr, "%d min %02d s", m, sec)
            else -> "$sec s"
        }
    }
}

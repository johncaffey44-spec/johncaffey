package com.johncaffey.vpnmaison

/**
 * Lecture « texte » d'une configuration WireGuard (format wg-quick, celui des
 * fichiers .conf et des QR codes générés par Freebox OS).
 *
 * Ce code est volontairement indépendant d'Android pour pouvoir être testé
 * sur ordinateur. La validation complète (clés, adresses) est faite ensuite par
 * la bibliothèque officielle WireGuard au moment de l'import.
 */
object WgText {

    /** Adresse « magique » de la Freebox : DNS + interface mafreebox.freebox.fr. */
    const val FREEBOX_MAGIC_IP = "212.27.38.253"

    private val DEFAULT_ROUTES = setOf("0.0.0.0/0", "::/0")

    data class Summary(
        val endpoint: String?,
        val addresses: List<String>,
        val dns: List<String>,
        val allowedIps: List<String>,
        val peerCount: Int,
    ) {
        /** Vrai si tout le trafic Internet passe par la Freebox. */
        val fullTunnel: Boolean get() = allowedIps.any { it in DEFAULT_ROUTES }
        val endpointHost: String? get() = endpoint?.substringBeforeLast(':')?.removeSurrounding("[", "]")
        val endpointPort: String? get() = endpoint?.substringAfterLast(':', "")?.ifBlank { null }
    }

    /** Vérification rapide avant l'analyse complète (évite d'importer n'importe quel QR code). */
    fun looksLikeWireGuard(text: String): Boolean {
        val t = text.lowercase()
        return "[interface]" in t && "[peer]" in t && "privatekey" in t
    }

    fun summary(text: String): Summary {
        var section = ""
        var endpoint: String? = null
        val addresses = mutableListOf<String>()
        val dns = mutableListOf<String>()
        val allowed = mutableListOf<String>()
        var peers = 0
        for (raw in text.lines()) {
            val line = raw.substringBefore('#').trim()
            if (line.isEmpty()) continue
            if (line.startsWith("[") && line.endsWith("]")) {
                section = line.lowercase()
                if (section == "[peer]") peers++
                continue
            }
            val key = line.substringBefore('=').trim().lowercase()
            val value = line.substringAfter('=', "").trim()
            when (section to key) {
                "[interface]" to "address" -> addresses += splitList(value)
                "[interface]" to "dns" -> dns += splitList(value)
                "[peer]" to "allowedips" -> allowed += splitList(value)
                "[peer]" to "endpoint" -> if (endpoint == null) endpoint = value
            }
        }
        return Summary(endpoint, addresses, dns, allowed, peers)
    }

    /**
     * Construit la variante « accès maison uniquement » : seul le trafic vers
     * le réseau local de la box (et le DNS de la Freebox) passe dans le tunnel,
     * le reste d'Internet sort directement par la 4G / le Wi-Fi.
     *
     * Retourne null si ce n'est pas possible (aucun réseau local dans la
     * configuration, ou plusieurs serveurs).
     *
     * Point important : si on retire seulement 0.0.0.0/0, le DNS de la Freebox
     * (212.27.38.253) n'est plus joignable depuis la 4G et plus aucun site ne
     * s'ouvre. On ajoute donc les serveurs DNS IPv4 (et l'adresse de
     * mafreebox.freebox.fr) dans le tunnel.
     */
    fun homeOnly(text: String): String? {
        val s = summary(text)
        if (s.peerCount != 1) return null
        val kept = s.allowedIps.filter { it !in DEFAULT_ROUTES }
        if (kept.isEmpty()) return null
        if (!s.fullTunnel) return text // déjà en mode « maison uniquement »

        val extra = (s.dns.filter(::isIpv4) + FREEBOX_MAGIC_IP).map { "$it/32" }
        val newList = (kept + extra).distinct()

        val out = StringBuilder()
        var section = ""
        var written = false
        for (raw in text.lines()) {
            val trimmed = raw.trim()
            if (trimmed.startsWith("[") && trimmed.endsWith("]")) section = trimmed.lowercase()
            val key = trimmed.substringBefore('=').trim().lowercase()
            if (section == "[peer]" && key == "allowedips" && !trimmed.startsWith("#")) {
                if (!written) {
                    out.append("AllowedIPs = ").append(newList.joinToString(", ")).append('\n')
                    written = true
                }
                continue // les lignes AllowedIPs suivantes sont fusionnées dans la première
            }
            out.append(raw).append('\n')
        }
        return out.toString().trimEnd() + "\n"
    }

    fun isIpv4(value: String): Boolean {
        val parts = value.split('.')
        return parts.size == 4 && parts.all { p -> p.isNotEmpty() && p.length <= 3 && p.all(Char::isDigit) && p.toInt() in 0..255 }
    }

    private fun splitList(value: String) =
        value.split(',').map { it.trim() }.filter { it.isNotEmpty() }
}

package com.johncaffey.vpnmaison

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class WgTextTest {

    // Forme typique d'un fichier généré par Freebox OS (clés factices).
    private val freebox = """
        [Interface]
        PrivateKey = aGVsbG8taGVsbG8taGVsbG8taGVsbG8taGVsbG8tMDA=
        Address = 192.168.27.65/32
        DNS = 212.27.38.253
        MTU = 1360

        [Peer]
        PublicKey = d29ybGQtd29ybGQtd29ybGQtd29ybGQtd29ybGQtMDA=
        Endpoint = 82.67.97.246:52724
        AllowedIPs = 0.0.0.0/0, 192.168.27.64/27, 192.168.1.0/24
    """.trimIndent()

    @Test
    fun recognisesWireGuardConfig() {
        assertTrue(WgText.looksLikeWireGuard(freebox))
        assertFalse(WgText.looksLikeWireGuard("https://exemple.fr"))
        assertFalse(WgText.looksLikeWireGuard("WIFI:S:MaBox;T:WPA;P:secret;;"))
    }

    @Test
    fun summaryReadsFreeboxFields() {
        val s = WgText.summary(freebox)
        assertEquals("82.67.97.246:52724", s.endpoint)
        assertEquals("82.67.97.246", s.endpointHost)
        assertEquals("52724", s.endpointPort)
        assertEquals(listOf("192.168.27.65/32"), s.addresses)
        assertEquals(listOf("212.27.38.253"), s.dns)
        assertEquals(1, s.peerCount)
        assertTrue(s.fullTunnel)
    }

    @Test
    fun ipv6EndpointIsSplitCorrectly() {
        val s = WgText.summary(freebox.replace("82.67.97.246:52724", "[2a01:e0a::1]:51820"))
        assertEquals("2a01:e0a::1", s.endpointHost)
        assertEquals("51820", s.endpointPort)
    }

    @Test
    fun homeOnlyKeepsLanAndRoutesFreeboxDns() {
        val out = WgText.homeOnly(freebox)!!
        val s = WgText.summary(out)
        assertFalse(s.fullTunnel)
        assertEquals(
            listOf("192.168.27.64/27", "192.168.1.0/24", "212.27.38.253/32"),
            s.allowedIps,
        )
        // Le reste du fichier est intact.
        assertTrue(out.contains("PrivateKey = aGVsbG8taGVsbG8taGVsbG8taGVsbG8taGVsbG8tMDA="))
        assertTrue(out.contains("Endpoint = 82.67.97.246:52724"))
        assertEquals(1, out.lines().count { it.startsWith("AllowedIPs") })
    }

    @Test
    fun homeOnlyMergesSeveralAllowedIpsLines() {
        val cfg = freebox.replace(
            "AllowedIPs = 0.0.0.0/0, 192.168.27.64/27, 192.168.1.0/24",
            "AllowedIPs = 0.0.0.0/0\nAllowedIPs = 192.168.1.0/24",
        )
        val s = WgText.summary(WgText.homeOnly(cfg)!!)
        assertEquals(listOf("192.168.1.0/24", "212.27.38.253/32"), s.allowedIps)
    }

    @Test
    fun homeOnlyImpossibleWithoutLanRoute() {
        val cfg = freebox.replace("0.0.0.0/0, 192.168.27.64/27, 192.168.1.0/24", "0.0.0.0/0, ::/0")
        assertNull(WgText.homeOnly(cfg))
    }

    @Test
    fun ipv4Detection() {
        assertTrue(WgText.isIpv4("212.27.38.253"))
        assertFalse(WgText.isIpv4("2a01::1"))
        assertFalse(WgText.isIpv4("256.1.1.1"))
        assertFalse(WgText.isIpv4("freebox.lan"))
    }

    @Test
    fun formatting() {
        assertEquals("512 o", Format.bytes(512))
        assertEquals("1,5 Ko", Format.bytes(1536))
        assertEquals("il y a 5 s", Format.ago(1_000, 6_000))
        assertEquals("2 min 05 s", Format.duration(125_000))
        assertEquals("1 h 01 min", Format.duration(3_660_000))
    }
}

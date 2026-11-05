"""Schémas d'écran du tutoriel (maquettes simplifiées, pas des captures)."""
from svg import (AMBER, BG, BLUE, GREEN, GREY, LIGHT_BLUE, LINE, RED, arrow, badge, browser, button,
                 checkbox, dialog, fake_qr, field, highlight, icon_floppy, icon_house, icon_power,
                 icon_qr, icon_shield, icon_trash, lines, note, phone, rect, svg, text, toggle, window)

W = 760  # largeur commune des schémas


# ------------------------------------------------------------------ 0. Principe


def concept():
    b = ""
    # téléphone
    b += f'<g transform="translate(40,18) scale(0.42)">{phone(0, 0, key=True)}</g>'
    b += lines(88, 228, ["Votre smartphone", "(4G, Wi-Fi d'hôtel…)"], 12.5, 600, "#202124", "middle")
    # nuage Internet
    b += ('<path d="M300,150 a34,34 0 0 1 10,-66 a44,44 0 0 1 80,-14 a36,36 0 0 1 62,22 a30,30 0 0 1 -6,58 z" '
          f'fill="{BG}" stroke="{LINE}" stroke-width="2"/>')
    b += text(378, 128, "Internet", 15, 700, GREY, "middle")
    # tunnel
    b += f'<path d="M150,108 C260,60 480,60 572,108" fill="none" stroke="{GREEN}" stroke-width="22" stroke-opacity="0.25" stroke-linecap="round"/>'
    b += f'<path d="M150,108 C260,60 480,60 572,108" fill="none" stroke="{GREEN}" stroke-width="3" stroke-dasharray="9 7"/>'
    b += f'<g transform="translate(360,58)"><rect x="-13" y="-4" width="26" height="20" rx="4" fill="{GREEN}"/><path d="M-7,-4 v-6 a7,7 0 0 1 14,0 v6" fill="none" stroke="{GREEN}" stroke-width="3.5"/></g>'
    b += text(360, 40, "Tunnel chiffré WireGuard", 13.5, 700, GREEN, "middle")
    # Freebox
    b += rect(560, 100, 120, 46, "#2B2D31", "#2B2D31", 10) + text(620, 129, "Freebox", 14, 700, "#fff", "middle")
    b += f'<circle cx="668" cy="112" r="3" fill="{GREEN}"/>'
    b += lines(620, 170, ["Serveur VPN", "intégré à la box"], 12, 600, "#202124", "middle")
    # maison
    b += icon_house(720, 70, 0.9, BLUE)
    b += f'<path d="M680,122 L706,96" stroke="{GREY}" stroke-width="2"/>'
    b += lines(722, 112, ["Maison :", "NAS, PC,", "caméras,", "imprimante"], 11.5, 500, "#202124", "middle")
    return svg(W, 250, b, "Principe : le téléphone se connecte à la Freebox par un tunnel chiffré")


# ------------------------------------------------------------------ 1. Freebox OS


def _fbx_desktop(w, h):
    return rect(0, 0, w, h, "#DCE3EC", "none", 0) + f'<path d="M0,{h} L{w},{h - 120} L{w},{h} z" fill="#C9D3E0"/>'


def login():
    body = _fbx_desktop(758, 268)
    body += f'<g transform="translate(230,40)">{rect(0, 0, 300, 190, "#fff", LINE, 12)}'
    body += text(150, 36, "Connexion à Freebox OS", 16, 700, "#202124", "middle")
    body += field(30, 76, 240, 32, "••••••••••••", "Mot de passe")
    body += button(30, 130, 240, 36, "Connexion")
    body += "</g>"
    b = browser(1, 1, 758, 330, "mafreebox.freebox.fr", body, "Freebox OS")
    b += highlight(12, 31, 200, 22, 11, 3) + badge(232, 42, 1)
    b += arrow(330, 22, 240, 36, 8) + note(338, 20, ["Tapez exactement cette adresse", "dans la barre du haut, puis Entrée"])
    b += highlight(260, 176, 240, 32, 6, 4) + badge(522, 192, 2)
    b += arrow(640, 190, 535, 192) + note(560, 230, ["Mot de passe", "administrateur", "de la Freebox"], size=12)
    return svg(W, 334, b, "Page de connexion Freebox OS")


def etat_internet():
    body = _fbx_desktop(758, 268)
    inner = rect(0, 0, 160, 230, BG, "none", 0)
    for i, item in enumerate(["État général", "État Internet", "État Wi-Fi", "Réseau local"]):
        if i == 1:
            inner += rect(0, 14 + i * 34, 160, 30, LIGHT_BLUE, "none", 0)
        inner += text(14, 34 + i * 34, item, 12.5, 700 if i == 1 else 500, BLUE if i == 1 else "#202124")
    inner += text(184, 34, "Connexion Internet", 15, 700)
    inner += text(184, 66, "Type : Fibre optique (FTTH)", 12.5)
    inner += text(184, 92, "Adresse IPv4 : ", 12.5) + text(274, 92, "82.65.xxx.xxx", 12.5, 700, BLUE)
    inner += f'<path d="M274,96 h86" stroke="{BLUE}" stroke-width="1.2"/>'
    inner += rect(184, 112, 300, 62, "#fff", LINE, 6)
    inner += text(196, 136, "Plage de ports : 16384 – 32767", 13, 700)
    inner += text(196, 158, "(exemple d'IPv4 partagée)", 11.5, 400, GREY, italic=True)
    body += window(40, 18, 560, 258, "État de la Freebox", inner)
    b = browser(1, 1, 758, 336, "mafreebox.freebox.fr", body)
    b += highlight(41 + 14, 61 + 18 + 28 + 48, 140, 26, 4, 2) + badge(30, 152, 1)
    b += highlight(41 + 274, 61 + 18 + 28 + 78, 90, 20, 4, 3) + badge(422, 175, 2)
    b += highlight(41 + 184, 61 + 18 + 28 + 112, 300, 62, 8, 4) + badge(540, 250, 3)
    b += arrow(690, 250, 556, 250) + note(612, 280, ["Si cette ligne", "existe : IPv4", "partagée"], size=12)
    return svg(W, 340, b, "État Internet : repérer une IPv4 partagée")


def _app_icon(x, y, label, glyph, hot=False):
    out = rect(x, y, 58, 58, "#fff", LINE, 14)
    out += glyph
    out += text(x + 29, y + 78, label, 11.5, 600 if hot else 500, "#202124", "middle")
    return out


def parametres():
    body = _fbx_desktop(758, 290)
    inner = rect(0, 0, 640, 34, BG, "none", 0)
    inner += text(110, 22, "Mode simplifié", 13, 500, GREY, "middle")
    inner += rect(200, 4, 130, 30, "#fff", LINE, 6) + text(265, 24, "Mode avancé", 13, 700, BLUE, "middle")
    labels = [("Gestion des ports", None), ("Mode réseau", None), ("DHCP", None), ("Serveur VPN", "vpn"),
              ("Client VPN", None), ("Nom de domaine", None), ("Wi-Fi", None), ("Accès à distance", None)]
    for i, (lab, kind) in enumerate(labels):
        col, row = i % 4, i // 4
        x, y = 40 + col * 150, 52 + row * 104
        if kind == "vpn":
            glyph = icon_shield(x + 29, y + 32, 0.62, BLUE)
        else:
            glyph = rect(x + 17, y + 17, 24, 24, "#BDC8D8", "none", 5)
        inner += _app_icon(x, y, lab, glyph, kind == "vpn")
    body += window(58, 10, 640, 280, "Paramètres de la Freebox", inner)
    b = browser(1, 1, 758, 360, "mafreebox.freebox.fr", body)
    ox, oy = 59, 61 + 10 + 28
    b += highlight(ox + 200, oy + 4, 130, 30, 6, 3) + badge(ox + 352, oy + 19, 1)
    b += highlight(ox + 490, oy + 52, 58, 88, 10, 6) + badge(ox + 572, oy + 60, 2)
    b += note(ox + 590, oy + 150, ["Double-clic sur", "« Serveur VPN »"], size=12)
    return svg(W, 364, b, "Paramètres de la Freebox, mode avancé, Serveur VPN")


def _vpn_sidebar(selected):
    out = rect(0, 0, 170, 262, BG, "none", 0)
    for i, item in enumerate(["Utilisateurs", "Connexions", "OpenVPN routé", "OpenVPN bridgé", "WireGuard"]):
        y = 14 + i * 36
        if item == selected:
            out += rect(0, y, 170, 32, LIGHT_BLUE, "none", 0) + rect(0, y, 4, 32, BLUE, "none", 0)
        out += text(16, y + 21, item, 13, 700 if item == selected else 500, BLUE if item == selected else "#202124")
    return out


def wireguard_activer():
    inner = _vpn_sidebar("WireGuard")
    inner += text(196, 34, "Serveur WireGuard", 16, 700)
    inner += checkbox(196, 58, True, "Activer")
    inner += field(196, 112, 230, 30, "(valeur proposée)", "Port")
    inner += field(196, 172, 230, 30, "(valeur proposée)", "MTU")
    inner += button(520, 214, 110, 34, "Appliquer")
    body = _fbx_desktop(758, 300) + window(50, 8, 650, 292, "Serveur VPN", inner)
    b = browser(1, 1, 758, 368, "mafreebox.freebox.fr", body)
    ox, oy = 51, 61 + 8 + 28
    b += highlight(ox, oy + 158, 170, 32, 4, 2) + badge(ox - 22, oy + 174, 1)
    b += highlight(ox + 196, oy + 58, 80, 16, 4, 6) + badge(ox + 300, oy + 66, 2)
    b += highlight(ox + 520, oy + 214, 110, 34, 6, 4) + badge(ox + 652, oy + 231, 3)
    b += rect(ox + 440, oy + 110, 180, 64, "#FFF4E0", AMBER, 6, 1.5)
    b += lines(ox + 452, oy + 132, ["Port et MTU :", "ne changez rien !", "(gardez les valeurs)"], 12, 700, "#7A4B00")
    return svg(W, 372, b, "Activer le serveur WireGuard")


def ajout_utilisateur():
    inner = _vpn_sidebar("Utilisateurs")
    inner += button(196, 14, 200, 30, "+ Ajouter un utilisateur", "outline", 12.5)
    inner += rect(170, 0, 480, 262, "rgba(0,0,0,0.30)", "none", 0)
    inner += rect(230, 54, 360, 200, "#fff", LINE, 10)
    inner += text(250, 80, "Ajouter un utilisateur", 15, 700)
    inner += field(250, 104, 320, 26, "telephone-marie", "Identifiant", mono=True)
    inner += field(250, 156, 320, 26, "WireGuard", "Type de serveur VPN", dropdown=True)
    inner += field(250, 208, 150, 26, "192.168.27.66", "Adresse IP fixe", mono=True)
    inner += button(470, 208, 100, 26, "Sauvegarder", size=12)
    body = _fbx_desktop(758, 300) + window(50, 8, 650, 292, "Serveur VPN", inner)
    b = browser(1, 1, 758, 368, "mafreebox.freebox.fr", body)
    ox, oy = 51, 61 + 8 + 28
    b += highlight(ox, oy + 14, 170, 32, 4, 2) + badge(ox - 22, oy + 30, 1)
    b += highlight(ox + 196, oy + 14, 200, 30, 6, 3) + badge(ox + 416, oy + 29, 2)
    b += highlight(ox + 250, oy + 104, 320, 26, 5, 3) + badge(ox + 592, oy + 117, 3)
    b += highlight(ox + 250, oy + 156, 320, 26, 5, 3) + badge(ox + 592, oy + 169, 4)
    b += highlight(ox + 470, oy + 208, 100, 26, 5, 3) + badge(ox + 592, oy + 221, 5)
    b += note(ox + 300, oy + 252, ["IP fixe : laissez celle proposée"], size=11.5)
    return svg(W, 372, b, "Ajouter un utilisateur WireGuard")


def utilisateur_qr():
    inner = _vpn_sidebar("WireGuard")
    inner += text(196, 34, "Utilisateurs WireGuard", 15, 700)
    inner += rect(196, 50, 300, 30, BG, LINE, 4)
    inner += text(206, 70, "Identifiant", 12, 700, GREY) + text(340, 70, "IP", 12, 700, GREY) + text(430, 70, "Actions", 12, 700, GREY)
    inner += rect(196, 80, 300, 40, "#fff", LINE, 0)
    inner += text(206, 105, "telephone-marie", 12.5, 600, family="JetBrains Mono, monospace")
    inner += text(340, 105, "…27.66", 12.5, 500, family="JetBrains Mono, monospace")
    inner += icon_floppy(424, 91, 18) + icon_qr(450, 91, 18, BLUE) + icon_trash(476, 91, 18)
    body = _fbx_desktop(758, 300) + window(30, 8, 520, 292, "Serveur VPN", inner)
    b = browser(1, 1, 758, 368, "mafreebox.freebox.fr", body)
    ox, oy = 31, 61 + 8 + 28
    b += highlight(ox + 450, oy + 91, 18, 18, 4, 4)
    b += arrow(ox + 459, oy + 150, ox + 459, oy + 122) + badge(ox + 459, oy + 166, 1)
    b += note(ox + 330, oy + 200, ["Smartphone : cliquez sur", "l'icône QR code"], size=12)
    b += f'<path d="M{ox + 424},{oy + 91} v-6" stroke="{GREY}"/>'
    b += note(ox + 196, oy + 238, ["Ordinateur : la disquette", "télécharge le fichier .conf"], size=11)
    b += f'<g transform="translate({ox + 520},{oy - 10})">' + rect(0, 0, 190, 250, "#fff", "#9AA0A6", 10, 1.5)
    b += text(95, 26, "Configuration WireGuard", 12, 700, "#202124", "middle")
    b += fake_qr(25, 44, 140) + text(95, 222, "Exemple illisible : le vôtre", 10.5, 500, GREY, "middle")
    b += text(95, 238, "est unique et SECRET", 10.5, 700, RED, "middle") + "</g>"
    b += arrow(ox + 478, oy + 96, ox + 516, oy + 60, -10)
    return svg(W, 372, b, "Afficher le QR code de l'utilisateur WireGuard")


# ------------------------------------------------------------------ 2. Android


def _android_title(title, back=True):
    out = rect(0, 0, 214, 44, "#fff", "none", 0)
    if back:
        out += f'<path d="M22,22 h-10 m5,-5 l-5,5 l5,5" fill="none" stroke="#202124" stroke-width="2"/>'
    out += text(40 if back else 14, 27, title, 14, 700)
    return out


def _vpnmaison_welcome(hot=False):
    out = text(14, 24, "VPN Maison", 15, 800)
    out += f'<circle cx="107" cy="88" r="40" fill="{BLUE}"/>' + icon_shield(107, 92, 0.9, "#fff", False)
    out += text(107, 154, "Bienvenue !", 16, 800, "#202124", "middle")
    out += lines(107, 176, ["Importez la configuration", "créée dans Freebox OS."], 11, 400, "#3C4043", "middle")
    out += button(14, 214, 186, 42, "Scanner le QR code", size=12.5, r=12)
    out += button(14, 272, 186, 38, "Importer un fichier .conf", "outline", 11.5, 12)
    out += button(14, 322, 186, 38, "Coller le texte", "outline", 11.5, 12)
    if hot:
        out += highlight(14, 214, 186, 42, 14, 4)
    return out


def _vpnmaison_main(state="down"):
    color = {"down": "#8A8F98", "up": GREEN}[state]
    out = text(14, 24, "VPN Maison", 15, 800)
    out += f'<circle cx="107" cy="112" r="62" fill="{color}"/>' + icon_power(107, 102, 24)
    out += text(107, 148, "Déconnecter" if state == "up" else "Connecter", 12.5, 700, "#fff", "middle")
    title = "Connecté à ma Freebox" if state == "up" else "Déconnecté"
    out += text(107, 202, title, 14.5, 800, GREEN if state == "up" else "#202124", "middle")
    if state == "up":
        out += text(107, 222, "✔ La Freebox répond", 11.5, 600, GREEN, "middle")
    out += rect(12, 238, 190, 104, BG, "none", 10)
    out += text(22, 258, "Ma connexion", 12, 800)
    rows = [("Adresse de la box", "82.65.x.x"), ("Port", "5xxxx"), ("Adresse tunnel", "…27.66/32")]
    if state == "up":
        rows.append(("Reçu / envoyé", "2,1 / 0,4 Mo"))
    for i, (k, v) in enumerate(rows):
        out += text(22, 280 + i * 18, k, 10.5) + text(194, 280 + i * 18, v, 10.5, 700, "#202124", "end")
    return out


def android_install():
    p1 = text(14, 24, "Téléchargements", 15, 800)
    p1 += rect(12, 40, 190, 44, BG, "none", 8) + text(22, 60, "VPN-Maison-1.0.0.apk", 11, 600) + text(22, 76, "4 Mo · Terminé", 10, 400, GREY)
    p1 += rect(0, -36, 214, 470, "rgba(0,0,0,0.38)", "none", 0)
    p1 += dialog(8, 120, 198, 180, "Applis inconnues", ["Pour votre sécurité, votre", "téléphone n'est pas", "autorisé à installer des", "applis provenant de", "cette source."], [("Annuler", False), ("Paramètres", True)], 11.5)

    p2 = _android_title("Installer des applis…")
    p2 += rect(12, 58, 190, 54, BG, "none", 10) + f'<circle cx="36" cy="85" r="13" fill="#4285F4"/>' + text(58, 90, "Chrome", 13, 700)
    p2 += text(14, 146, "Autoriser cette source", 12.5, 600) + toggle(156, 132, True, BLUE)
    p2 += highlight(156, 132, 42, 24, 12, 4)
    p2 += lines(14, 196, ["Puis revenez en arrière", "(flèche ← ou geste retour)."], 11, 400, GREY)

    p3 = text(14, 24, "Téléchargements", 15, 800)
    p3 += rect(0, -36, 214, 470, "rgba(0,0,0,0.38)", "none", 0)
    p3 += f'<g transform="translate(8,140)">' + rect(2, 4, 198, 150, "rgba(0,0,0,0.18)", "none", 16) + rect(0, 0, 198, 150, "#fff", "none", 16)
    p3 += f'<circle cx="34" cy="34" r="18" fill="{BLUE}"/>' + icon_shield(34, 37, 0.4, "#fff", False)
    p3 += text(62, 40, "VPN Maison", 15, 800)
    p3 += lines(16, 82, ["Voulez-vous installer", "cette application ?"], 12, 400, "#3C4043")
    p3 += text(92, 134, "Annuler", 13, 700, BLUE, "middle") + text(160, 134, "Installer", 13, 700, BLUE, "middle")
    p3 += highlight(130, 118, 60, 24, 6, 2) + "</g>"

    b = phone(10, 0, p1) + phone(265, 0, p2) + phone(520, 0, p3)
    b += badge(28, 452, 1, 16) + badge(283, 452, 2, 16) + badge(538, 452, 3, 16)
    b += arrow(244, 236, 276, 236) + arrow(499, 236, 531, 236)
    return svg(W, 480, b, "Installer l'APK sur Android")


def android_import():
    p1 = _vpnmaison_welcome(hot=True)
    p2 = _vpnmaison_welcome()
    p2 += rect(0, -36, 214, 470, "rgba(0,0,0,0.38)", "none", 0)
    p2 += f'<g transform="translate(8,110)">' + rect(2, 4, 198, 210, "rgba(0,0,0,0.18)", "none", 16) + rect(0, 0, 198, 210, "#fff", "none", 16)
    p2 += lines(99, 34, ["Autoriser VPN Maison à", "prendre des photos et", "enregistrer des vidéos ?"], 12.5, 700, "#202124", "middle")
    for i, opt in enumerate(["Pendant l'utilisation de l'appli", "Uniquement cette fois-ci", "Ne pas autoriser"]):
        y = 96 + i * 38
        p2 += rect(12, y, 174, 30, LIGHT_BLUE if i == 0 else BG, "none", 15) + text(99, y + 19, opt, 10.5, 600, BLUE, "middle")
    p2 += highlight(12, 96, 174, 30, 15, 3) + "</g>"
    p3 = rect(0, -36, 214, 470, "#222", "none", 0)
    p3 += rect(32, 92, 150, 150, "none", "#fff", 8, 2) + fake_qr(52, 112, 110, 3)
    p3 += f'<path d="M32,167 h150" stroke="{RED}" stroke-width="2"/>'
    p3 += lines(107, 282, ["Visez le QR code WireGuard", "affiché dans Freebox OS"], 11.5, 600, "#fff", "middle")
    p3 += rect(28, 360, 158, 30, "#3C4043", "none", 15) + text(107, 380, "Configuration importée ✔", 11, 600, "#fff", "middle")
    b = phone(10, 0, p1) + phone(265, 0, p2) + phone(520, 0, p3)
    b += badge(28, 452, 1, 16) + badge(283, 452, 2, 16) + badge(538, 452, 3, 16)
    b += arrow(244, 236, 276, 236) + arrow(499, 236, 531, 236)
    return svg(W, 480, b, "Importer la configuration avec le QR code")


def android_connect():
    p1 = _vpnmaison_main("down") + highlight(45, 50, 124, 124, 62, 5)
    p2 = _vpnmaison_main("down") + rect(0, -36, 214, 470, "rgba(0,0,0,0.38)", "none", 0)
    p2 += dialog(8, 96, 198, 228, "Demande de connexion",
                 ["VPN Maison souhaite", "configurer une connexion", "VPN qui lui permet de", "surveiller le trafic réseau.",
                  "N'acceptez que si vous", "faites confiance à la", "source."], [("Annuler", False), ("OK", True)], 11.5)
    p3 = _vpnmaison_main("up")
    b = phone(10, 0, p1) + phone(265, 0, p2) + phone(520, 0, p3, key=True)
    b += badge(28, 452, 1, 16) + badge(283, 452, 2, 16) + badge(538, 452, 3, 16)
    b += arrow(244, 236, 276, 236) + arrow(499, 236, 531, 236)
    return svg(W, 480, b, "Se connecter et accepter la demande VPN d'Android")


def android_extras():
    p1 = text(14, 24, "VPN Maison", 15, 800)
    p1 += rect(12, 40, 190, 152, BG, "none", 10)
    p1 += text(22, 62, "Tester l'accès à ma Freebox", 12, 800)
    p1 += lines(22, 80, ["Coupez le Wi-Fi pour un", "vrai test (4G/5G seule)."], 10.5, 400, "#3C4043")
    p1 += button(22, 106, 120, 30, "Lancer le test", "outline", 11.5, 15)
    p1 += f'<circle cx="32" cy="164" r="8" fill="{GREEN}"/>' + f'<path d="M28,164 l3,3 l5,-6" stroke="#fff" stroke-width="2" fill="none"/>'
    p1 += lines(46, 160, ["Bravo ! Votre box", "répond : Freebox Pop"], 11, 700, GREEN)
    p1 += highlight(22, 106, 120, 30, 15, 3)

    p2 = rect(0, -36, 214, 470, "#E8EAED", "none", 0)
    p2 += text(14, 10, "Réglages rapides", 12, 700, GREY)
    tiles = [("Wi-Fi", True), ("Bluetooth", False), ("VPN Maison", True), ("Lampe", False), ("Avion", False), ("Rotation", False)]
    for i, (name, on) in enumerate(tiles):
        x, y = 12 + (i % 2) * 98, 28 + (i // 2) * 62
        p2 += rect(x, y, 92, 52, BLUE if on else "#fff", "none", 18)
        p2 += text(x + 12, y + 31, name, 11.5, 700, "#fff" if on else "#202124")
    p2 += highlight(12, 90, 92, 52, 18, 4)
    p2 += lines(14, 230, ["Ajout : glissez 2 fois vers", "le bas, touchez le crayon ✎", "puis faites glisser la tuile", "« VPN Maison »."], 11, 400, "#3C4043")

    p3 = _android_title("VPN")
    p3 += rect(12, 58, 190, 50, BG, "none", 10) + text(24, 80, "VPN Maison", 13, 700) + text(24, 97, "Connecté", 10.5, 500, GREEN)
    p3 += f'<circle cx="180" cy="83" r="9" fill="none" stroke="{GREY}" stroke-width="2"/><circle cx="180" cy="83" r="3" fill="{GREY}"/>'
    p3 += highlight(170, 73, 20, 20, 10, 3)
    p3 += text(14, 146, "VPN permanent", 12.5, 600) + toggle(156, 132, True, BLUE)
    p3 += lines(14, 172, ["Reste connecté en", "permanence (optionnel)."], 10.5, 400, GREY)
    p3 += highlight(156, 132, 42, 24, 12, 4)
    b = phone(10, 0, p1) + phone(265, 0, p2) + phone(520, 0, p3)
    b += badge(28, 452, "A", 16) + badge(283, 452, "B", 16) + badge(538, 452, "C", 16)
    return svg(W, 480, b, "Tester, tuile des réglages rapides, VPN permanent")


# ------------------------------------------------------------------ 3. iPhone


def ios_install():
    p1 = text(14, 30, "Rechercher", 20, 800)
    p1 += rect(12, 44, 190, 30, "#EEEEF0", "none", 10) + text(26, 64, "wireguard", 12.5, 500)
    p1 += rect(12, 92, 44, 44, "#88171A", "none", 10) + text(34, 120, "W", 20, 800, "#fff", "middle")
    p1 += text(64, 108, "WireGuard", 13.5, 700) + text(64, 126, "WireGuard Development…", 10, 400, GREY)
    p1 += rect(150, 100, 52, 26, "#EEEEF0", "none", 13) + text(176, 118, "Obtenir", 11.5, 700, "#007AFF", "middle")
    p1 += highlight(150, 100, 52, 26, 13, 3)

    p2 = text(14, 30, "WireGuard", 16, 800) + text(196, 30, "+", 22, 400, "#007AFF", "end")
    p2 += rect(0, 120, 214, 300, "rgba(0,0,0,0.30)", "none", 0)
    p2 += rect(8, 210, 198, 152, "#F7F7F9", "none", 14)
    opts = ["Créer depuis un fichier/archive", "Créer à partir d'un code QR", "Créer de zéro"]
    for i, o in enumerate(opts):
        p2 += text(107, 240 + i * 44, o, 11.5, 600 if i == 1 else 400, "#007AFF", "middle")
        if i < 2:
            p2 += f'<path d="M8,{254 + i * 44} h198" stroke="#DADADF"/>'
    p2 += rect(8, 370, 198, 40, "#fff", "none", 14) + text(107, 395, "Annuler", 12.5, 700, "#007AFF", "middle")
    p2 += highlight(14, 270, 186, 32, 8, 2)
    p2 += highlight(186, 12, 16, 22, 4, 3)

    p3 = text(14, 30, "WireGuard", 16, 800)
    p3 += rect(0, -36, 214, 470, "rgba(0,0,0,0.38)", "none", 0)
    p3 += rect(18, 120, 178, 170, "#F2F2F7", "none", 14)
    p3 += lines(107, 148, ["« WireGuard » souhaite", "ajouter des", "configurations VPN"], 12.5, 700, "#000", "middle")
    p3 += lines(107, 204, ["L'activité réseau peut être", "filtrée ou surveillée", "par le VPN."], 10, 400, "#3C3C43", "middle")
    p3 += f'<path d="M18,250 h178 M107,250 v40" stroke="#C6C6C8"/>'
    p3 += text(62, 275, "Autoriser", 13, 700, "#007AFF", "middle") + text(152, 275, "Refuser", 13, 400, "#007AFF", "middle")
    p3 += highlight(24, 256, 78, 28, 6, 2)
    b = phone(10, 0, p1, "ios") + phone(265, 0, p2, "ios") + phone(520, 0, p3, "ios")
    b += badge(28, 452, 1, 16) + badge(283, 452, 2, 16) + badge(538, 452, 3, 16)
    b += arrow(244, 236, 276, 236) + arrow(499, 236, 531, 236)
    return svg(W, 480, b, "iPhone : installer WireGuard et ajouter le tunnel")


def ios_connect():
    def tunnel_list(on):
        o = text(14, 30, "WireGuard", 16, 800) + text(196, 30, "+", 22, 400, "#007AFF", "end")
        o += rect(12, 50, 190, 50, "#F2F2F7", "none", 10)
        o += f'<circle cx="28" cy="75" r="5" fill="{GREEN if on else "#C7C7CC"}"/>'
        o += text(42, 80, "Freebox", 14, 700) + toggle(150, 63, on, GREEN)
        return o

    p1 = tunnel_list(False) + highlight(150, 63, 42, 24, 12, 4)
    p2 = tunnel_list(True)
    p3 = rect(12, 0, 190, 28, "#EEEEF0", "none", 9) + text(107, 19, "mafreebox.freebox.fr", 11, 500, "#202124", "middle")
    p3 += rect(0, 34, 214, 400, "#DCE3EC", "none", 0)
    p3 += rect(22, 110, 170, 140, "#fff", LINE, 10) + text(107, 140, "Freebox OS", 14, 800, "#202124", "middle")
    p3 += field(36, 166, 142, 26, "••••••••") + button(36, 204, 142, 28, "Connexion", size=11.5)
    p3 += lines(107, 290, ["La page de votre box", "s'ouvre en 4G :", "le VPN fonctionne !"], 12, 700, GREEN, "middle")
    b = phone(10, 0, p1, "ios") + phone(265, 0, p2, "ios") + phone(520, 0, p3, "ios")
    # pastille « VPN » dans la barre d'état (téléphones 2 et 3)
    for px in (265, 520):
        b += rect(px + 150, 17, 28, 14, "#fff", "#202124", 3, 1.2) + text(px + 164, 28, "VPN", 9, 800, "#202124", "middle")
    b += highlight(265 + 150, 17, 28, 14, 4, 3)
    b += badge(28, 452, 4, 16) + badge(283, 452, 5, 16) + badge(538, 452, 6, 16)
    b += arrow(244, 236, 276, 236) + arrow(499, 236, 531, 236)
    return svg(W, 480, b, "iPhone : activer le tunnel et tester")


ALL = {
    "concept": concept, "login": login, "etat_internet": etat_internet, "parametres": parametres,
    "wireguard_activer": wireguard_activer, "ajout_utilisateur": ajout_utilisateur, "utilisateur_qr": utilisateur_qr,
    "android_install": android_install, "android_import": android_import, "android_connect": android_connect,
    "android_extras": android_extras, "ios_install": ios_install, "ios_connect": ios_connect,
}

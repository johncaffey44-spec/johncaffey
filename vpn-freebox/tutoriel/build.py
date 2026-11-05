"""Génère tutoriel.html (puis le PDF via render.js).

Usage :  python3 build.py && node render.js
"""
import pathlib

import figures as F
from svg import BLUE, GREEN, icon_house, icon_shield, phone, svg, text

HERE = pathlib.Path(__file__).parent
REPO = "johncaffey44-spec/johncaffey"
APK = "VPN-Maison-1.0.0.apk"


def fig(name, caption, small=False):
    return (f'<figure class="fig{" small" if small else ""}">{F.ALL[name]()}<figcaption>{caption} '
            '<em>— Schéma simplifié : votre écran peut être un peu différent.</em></figcaption></figure>')


def step(n, title, body, keep=False):
    return f'<section class="step{" keep" if keep else ""}"><div class="num">{n}</div><div class="body"><h3>{title}</h3>{body}</div></section>'


def box(kind, title, body):
    sym = {"attention": "!", "astuce": "i", "ok": "✓", "info": "?"}[kind]
    return f'<aside class="callout {kind}"><div class="sym">{sym}</div><div><strong>{title}</strong>{body}</div></aside>'


def part(n, title, subtitle=""):
    sub = f"<p class='sub'>{subtitle}</p>" if subtitle else ""
    return f'<header class="part"><span>PARTIE {n}</span><h2>{title}</h2>{sub}</header>'


def cover_art():
    b = f'<path d="M120,150 C230,40 430,40 540,140" fill="none" stroke="{GREEN}" stroke-width="26" stroke-opacity="0.25" stroke-linecap="round"/>'
    b += f'<path d="M120,150 C230,40 430,40 540,140" fill="none" stroke="{GREEN}" stroke-width="4" stroke-dasharray="11 8"/>'
    b += f'<g transform="translate(40,60) scale(0.5)">{phone(0, 0, key=True)}</g>'
    b += icon_shield(330, 82, 1.5, BLUE)
    b += icon_house(590, 150, 2.0, BLUE)
    b += text(330, 160, "Tunnel chiffré", 15, 700, GREEN, "middle")
    return svg(680, 300, b, "Illustration : téléphone relié à la maison par un tunnel chiffré")


CSS = """
@page { size: A4; margin: 14mm 14mm 16mm 14mm; }
* { box-sizing: border-box; }
body { font-family: Inter, 'DejaVu Sans', sans-serif; font-size: 10.6pt; line-height: 1.45; color: #202124; margin: 0; }
h2, h3 { margin: 0; }
p { margin: 0.35em 0 0.55em; }
ul, ol { margin: 0.3em 0 0.6em; padding-left: 1.3em; }
li { margin: 0.18em 0; }
code, .mono { font-family: 'JetBrains Mono', monospace; font-size: 0.92em; background: #EEF1F5; padding: 1px 5px; border-radius: 4px; }
.key { display: inline-block; border: 1.5px solid #9AA0A6; border-bottom-width: 3px; border-radius: 5px; padding: 0 6px; font-weight: 600; font-size: 0.9em; }
.btn { display: inline-block; background: #0B3D91; color: #fff; border-radius: 5px; padding: 0 7px; font-weight: 600; font-size: 0.92em; }
.menu { font-weight: 700; color: #0B3D91; }
.arrow { color: #E3262F; font-weight: 800; }

/* Couverture */
.cover { break-after: page; }
.cover .art svg { height: 58mm; width: auto; margin: 0 auto; display: block; }
.cover .band { background: linear-gradient(135deg, #0B3D91, #1A5BC4); color: #fff; border-radius: 14px; padding: 26px 28px 22px; }
.cover .band small { letter-spacing: .14em; font-weight: 700; opacity: .85; }
.cover h1 { font-size: 40pt; margin: 6px 0 4px; letter-spacing: -0.02em; }
.cover .band p { font-size: 14pt; margin: 0; opacity: .95; }
.cover .art { margin: 14px 0 6px; }
.cover .facts { display: flex; gap: 10px; margin: 6px 0 14px; }
.cover .facts div { flex: 1; background: #F4F6F9; border-radius: 10px; padding: 9px 12px; font-size: 10pt; }
.cover .facts b { display: block; font-size: 13pt; color: #0B3D91; }
.legend { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 18px; font-size: 9.6pt; margin-top: 6px; }
.legend div { display: flex; align-items: center; gap: 8px; }
.b-red { width: 22px; height: 22px; border-radius: 50%; background: #E3262F; color: #fff; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; font-size: 11pt; flex: none; }
.f-red { width: 30px; height: 18px; border: 3px solid #E3262F; border-radius: 5px; flex: none; }
.a-red { color: #E3262F; font-size: 18pt; font-weight: 800; line-height: 1; flex: none; }

/* Sommaire */
.toc { break-after: page; }
.toc h2 { font-size: 20pt; color: #0B3D91; margin-bottom: 10px; }
.toc ol { list-style: none; padding: 0; }
.toc > ol > li { display: flex; gap: 12px; padding: 9px 12px; border-radius: 10px; background: #F4F6F9; margin-bottom: 7px; align-items: baseline; }
.toc > ol > li b { color: #0B3D91; min-width: 82px; }
.toc > ol > li span { color: #5F6368; font-size: 9.5pt; margin-left: auto; }

/* Parties */
.part { break-before: page; background: #0B3D91; color: #fff; border-radius: 12px; padding: 14px 20px; margin-bottom: 14px; }
.part span { font-size: 9pt; letter-spacing: .16em; font-weight: 700; opacity: .8; }
.part h2 { font-size: 19pt; letter-spacing: -0.01em; }
.part .sub { margin: 4px 0 0; opacity: .9; font-size: 10.5pt; }
.part.android { background: #1E7A3A; }
.part.ios { background: #3C4043; }
.part.annexe { background: #5F6368; }
h4 { font-size: 12.5pt; color: #0B3D91; margin: 12px 0 4px; }

/* Étapes */
.step { display: flex; gap: 12px; margin: 10px 0 6px; }
.step .num { flex: none; width: 34px; height: 34px; border-radius: 50%; background: #0B3D91; color: #fff; font-weight: 800; font-size: 14pt; display: flex; align-items: center; justify-content: center; margin-top: 1px; }
.step .body { flex: 1; min-width: 0; }
.step h3 { font-size: 13pt; margin: 4px 0 4px; }
.fig { margin: 8px 0 6px; break-inside: avoid; }
.fig svg { width: 100%; height: auto; display: block; }
.fig.small svg { width: 86%; margin: 0 auto; }
.step.keep { break-inside: avoid; }
.step h3 { break-after: avoid; }
.fig figcaption { font-size: 8.6pt; color: #5F6368; text-align: center; margin-top: 3px; }
.avoid { break-inside: avoid; }

/* Encadrés */
.callout { display: flex; gap: 10px; border-radius: 10px; padding: 9px 12px; margin: 8px 0; break-inside: avoid; border: 2px solid; }
.callout .sym { flex: none; width: 24px; height: 24px; border-radius: 50%; color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 12pt; }
.callout strong { display: block; margin-bottom: 2px; }
.callout p, .callout ul, .callout ol { margin: 2px 0; }
.attention { background: #FDECEA; border-color: #E3262F; } .attention .sym { background: #E3262F; }
.astuce { background: #E8F0FE; border-color: #1A73E8; } .astuce .sym { background: #1A73E8; }
.ok { background: #E6F4EA; border-color: #1E8E3E; } .ok .sym { background: #1E8E3E; }
.info { background: #FEF7E0; border-color: #F29900; } .info .sym { background: #F29900; }

/* Tableaux */
table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 9.6pt; margin: 6px 0; }
th { background: #0B3D91; color: #fff; text-align: left; padding: 7px 9px; }
th:first-child { border-top-left-radius: 8px; } th:last-child { border-top-right-radius: 8px; }
td { padding: 7px 9px; vertical-align: top; border-bottom: 1px solid #DADCE0; }
tr { break-inside: avoid; }
tr:nth-child(even) td { background: #F8F9FB; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.card { background: #F4F6F9; border-radius: 10px; padding: 10px 13px; break-inside: avoid; }
.card h4 { margin-top: 0; }
.checklist li { list-style: none; position: relative; padding-left: 4px; }
.checklist li::before { content: "☐"; position: absolute; left: -1.2em; color: #0B3D91; font-weight: 700; }
"""


def build():
    h = []
    # ------------------------------------------------------------------ couverture
    h.append(f"""
<div class="cover">
  <div class="band">
    <small>TUTORIEL PAS À PAS · DÉBUTANT</small>
    <h1>VPN Maison</h1>
    <p>Se connecter à sa Freebox depuis son smartphone,<br>partout et en toute sécurité.</p>
  </div>
  <div class="art">{cover_art()}</div>
  <div class="facts">
    <div><b>20 – 30 min</b>pour tout installer</div>
    <div><b>0 €</b>le VPN est inclus dans la Freebox</div>
    <div><b>Android &amp; iPhone</b>avec un QR code</div>
  </div>
  {box("info", "Ce qu'il vous faut", '''<ul>
    <li>Une Freebox avec serveur VPN WireGuard : <b>Révolution, Mini 4K, One, Delta, Pop ou Ultra</b>, à jour.
        <i>(Pas la Freebox Crystal ni les anciennes « HD ».)</i></li>
    <li>Un <b>ordinateur branché sur la Freebox</b> (Wi-Fi ou câble) et le <b>mot de passe de Freebox OS</b>.</li>
    <li>Un smartphone <b>Android 7 ou plus récent</b>, ou un <b>iPhone iOS 15 ou plus récent</b>.</li>
    <li>La <b>4G/5G</b> sur le téléphone, pour faire le test final.</li></ul>''')}
  <h4>Comment lire les schémas</h4>
  <div class="legend">
    <div><span class="b-red">1</span> L'ordre dans lequel cliquer ou toucher</div>
    <div><span class="f-red"></span> Encadré rouge : l'endroit exact où cliquer</div>
    <div><span class="a-red">➜</span> Flèche rouge : regardez ici / passez à l'écran suivant</div>
    <div><span class="b-red" style="background:#0B3D91">7</span> Numéro d'étape dans le texte</div>
  </div>
  {box("astuce", "Encadrés de couleur", "<p><b style='color:#E3262F'>Rouge</b> = attention, à lire absolument · <b style='color:#1A73E8'>Bleu</b> = astuce · <b style='color:#1E8E3E'>Vert</b> = vérification avant de continuer · <b style='color:#B06D00'>Jaune</b> = information.</p>")}
</div>""")

    # ------------------------------------------------------------------ sommaire
    h.append("""
<div class="toc"><h2>Sommaire</h2><ol>
<li><b>Partie 0</b> Comprendre en 2 minutes<span>Ce que fait (et ne fait pas) un VPN</span></li>
<li><b>Partie 1</b> Configurer la Freebox<span>sur l'ordinateur · étapes 1 à 6</span></li>
<li><b>Partie 2</b> Android : l'application VPN Maison<span>étapes 7 à 12</span></li>
<li><b>Partie 3</b> iPhone : l'application WireGuard<span>étapes 13 à 16</span></li>
<li><b>Partie 4</b> Tester comme il faut<span>4 vérifications</span></li>
<li><b>Partie 5</b> En cas de problème<span>tableau de dépannage</span></li>
<li><b>Partie 6</b> Sécurité : les bons réflexes<span>à lire une fois</span></li>
<li><b>Annexe A</b> Pourquoi pas de fichier .ipa prêt à installer ?<span>et comment le compiler</span></li>
<li><b>Annexe B</b> Transparence : ce que fait l'application<span>permissions, données</span></li>
</ol>
""" + box("attention", "Avant de commencer : à savoir", """<ul>
<li>Faites la <b>partie 1 sur l'ordinateur</b>, puis la partie 2 (Android) <b>ou</b> 3 (iPhone) avec le téléphone, en gardant l'écran de l'ordinateur ouvert sur le QR code.</li>
<li>Les menus de Freebox OS changent parfois de nom ou de place selon la version. Si un libellé diffère un peu, cherchez le mot le plus proche.</li>
<li>Comptez <b>un utilisateur VPN par appareil</b> (un pour votre téléphone, un pour celui de votre conjoint, etc.).</li></ul>""") + "</div>")

    # ------------------------------------------------------------------ partie 0
    h.append(part(0, "Comprendre en 2 minutes", "Pas besoin de tout retenir : juste l'idée générale."))
    h.append(fig("concept", "Le téléphone, où qu'il soit, ouvre un tunnel chiffré jusqu'à la Freebox.", small=True))
    h.append("""
<p>Un <b>VPN</b> (« réseau privé virtuel ») crée un <b>tunnel chiffré</b> entre votre téléphone et votre Freebox.
Tout ce qui passe dans ce tunnel est illisible pour les autres : le Wi-Fi de l'hôtel, l'opérateur mobile, un pirate sur le même réseau…
Pour vos appareils de la maison, c'est <b>comme si votre téléphone était chez vous</b>.</p>
<div class="two">
 <div class="card"><h4>Ce que ça permet</h4><ul>
  <li>Ouvrir l'interface de la box (<code>mafreebox.freebox.fr</code>) depuis n'importe où.</li>
  <li>Accéder au disque de la box, à un NAS, à un PC ou à des caméras de la maison.</li>
  <li>Utiliser un Wi-Fi public (gare, hôtel, café) sans risque d'espionnage.</li>
  <li>Naviguer avec l'adresse Internet de votre maison, même en déplacement.</li></ul></div>
 <div class="card"><h4>Ce que ça ne fait PAS</h4><ul>
  <li><b>Pas d'anonymat</b> : les sites voient l'adresse Internet de votre Freebox (donc la vôtre).</li>
  <li><b>Pas plus rapide</b> : la vitesse est limitée par l'envoi (débit montant) de votre box. Fibre : très bien. ADSL : plus lent.</li>
  <li><b>Pas un antivirus</b> : il ne protège pas des faux sites ni des pièces jointes piégées.</li></ul></div>
</div>
<h4>Les 4 mots à connaître</h4>
<table><tr><th style="width:28%">Mot</th><th>Ce que ça veut dire</th></tr>
<tr><td><b>Freebox OS</b></td><td>Le « tableau de bord » de votre box, qu'on ouvre dans le navigateur à l'adresse <code>mafreebox.freebox.fr</code>.</td></tr>
<tr><td><b>WireGuard</b></td><td>La technologie de VPN utilisée : moderne, rapide, économe en batterie. Intégrée gratuitement à la Freebox.</td></tr>
<tr><td><b>QR code de configuration</b></td><td>Un carré de points qui contient l'adresse de votre box et <b>votre clé secrète</b>. Le téléphone le lit avec sa caméra.</td></tr>
<tr><td><b>IPv4 partagée</b></td><td>Chez Free, certaines lignes partagent leur adresse Internet avec d'autres abonnés ; seule une « plage de ports » vous est réservée. On vérifie ça à l'étape 2.</td></tr>
</table>
""")

    # ------------------------------------------------------------------ partie 1
    h.append(part(1, "Configurer la Freebox", "Sur l'ordinateur connecté à la box. Environ 10 minutes."))
    h.append(step(1, "Ouvrir Freebox OS", f"""
<p>Sur l'ordinateur relié à la Freebox, ouvrez votre navigateur (Chrome, Firefox, Edge, Safari). Dans la barre d'adresse <b>tout en haut</b>,
tapez <code>mafreebox.freebox.fr</code> puis appuyez sur <span class="key">Entrée</span>. Saisissez le <b>mot de passe administrateur</b> puis cliquez sur <span class="btn">Connexion</span>.</p>
{fig("login", "Étape 1 : ouvrir Freebox OS et se connecter.")}
{box("astuce", "Première connexion ou mot de passe oublié ?", "<p>Freebox OS vous demande de créer un mot de passe, puis de <b>confirmer sur l'écran de la Freebox</b> (afficheur du Server ou télévision selon le modèle). Utilisez le lien « mot de passe oublié » si besoin : la validation se fait aussi sur la box.</p>")}
"""))
    h.append(step(2, "Vérifier le type d'adresse Internet (important)", f"""
<p>Double-cliquez sur <span class="menu">État de la Freebox</span>, puis à gauche sur <span class="menu">État Internet</span>.
Cliquez sur votre <b>adresse IPv4</b> pour afficher les détails.</p>
{fig("etat_internet", "Étape 2 : si une « plage de ports » s'affiche, votre adresse IPv4 est partagée.")}
<ul><li><b>Pas de plage de ports</b> : parfait, passez à l'étape 3.</li>
<li><b>Une plage de ports s'affiche</b> (ex. 16384 – 32767) : pas de panique, ça marche quand même. Retenez seulement la règle :
<b>ne modifiez jamais le port proposé</b> à l'étape 4 (Freebox OS le choisit dans votre plage).</li></ul>
{box("info", "Si le VPN refuse de fonctionner avec une IPv4 partagée", "<p>Dans votre <b>Espace Abonné Free</b> (site free.fr) : <span class='menu'>Ma Freebox</span> <span class='arrow'>➜</span> <span class='menu'>Demander une adresse IP fixe V4 full-stack</span>. L'option est <b>gratuite</b> ; Free la présente comme <b>irréversible</b> et « réservée aux utilisateurs avancés ». Redémarrez la Freebox environ 30 minutes après la demande. Votre box reste protégée : rien n'est ouvert sans votre accord.</p>")}
""", keep=True))
    h.append(step(3, "Ouvrir le « Serveur VPN »", f"""
<p>Double-cliquez sur <span class="menu">Paramètres de la Freebox</span>. En haut de la fenêtre, cliquez sur l'onglet
<span class="menu">Mode avancé</span> <span class="b-red" style="width:18px;height:18px;font-size:9pt">1</span>,
puis double-cliquez sur l'icône <span class="menu">Serveur VPN</span> <span class="b-red" style="width:18px;height:18px;font-size:9pt">2</span>.</p>
{fig("parametres", "Étape 3 : Paramètres de la Freebox › Mode avancé › Serveur VPN.")}
""", keep=True))
    h.append(step(4, "Activer WireGuard", f"""
<p>Dans le menu de gauche, cliquez sur <span class="menu">WireGuard</span> <span class="b-red" style="width:18px;height:18px;font-size:9pt">1</span>.
Cochez <b>Activer</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">2</span>, ne touchez pas au <b>Port</b> ni au <b>MTU</b>,
puis cliquez sur <span class="btn">Appliquer</span> <span class="b-red" style="width:18px;height:18px;font-size:9pt">3</span>.</p>
{fig("wireguard_activer", "Étape 4 : cocher « Activer » puis « Appliquer ».")}
""", keep=True))
    h.append(step(5, "Créer un utilisateur pour le téléphone", f"""
<p>À gauche, cliquez sur <span class="menu">Utilisateurs</span> <span class="b-red" style="width:18px;height:18px;font-size:9pt">1</span>, puis sur
<span class="menu">Ajouter un utilisateur</span> <span class="b-red" style="width:18px;height:18px;font-size:9pt">2</span>. Remplissez :</p>
<ol><li><b>Identifiant</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">3</span> : un nom clair, <b>sans espace ni accent</b>, par exemple <code>telephone-marie</code>.</li>
<li><b>Type de serveur VPN</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">4</span> : choisissez <b>WireGuard</b>.</li>
<li><b>Adresse IP fixe</b> : laissez celle proposée.</li>
<li>Cliquez sur <span class="btn">Sauvegarder</span> <span class="b-red" style="width:18px;height:18px;font-size:9pt">5</span>.</li></ol>
{fig("ajout_utilisateur", "Étape 5 : un utilisateur WireGuard par appareil.")}
{box("astuce", "Un utilisateur = un appareil", "<p>Une même configuration ne peut servir qu'à <b>un appareil à la fois</b>. Pour un deuxième téléphone, une tablette ou un PC portable, recommencez cette étape avec un autre identifiant (<code>tablette-salon</code>, <code>pc-portable</code>…).</p>")}
"""))
    h.append(step(6, "Afficher le QR code", f"""
<p>Revenez dans <span class="menu">WireGuard</span> (ou dans la liste des utilisateurs). Sur la ligne de votre utilisateur, cliquez sur
l'<b>icône QR code</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">1</span> : il s'affiche en grand.
<b>Laissez-le à l'écran</b> et prenez votre téléphone (partie 2 pour Android, partie 3 pour iPhone).</p>
{fig("utilisateur_qr", "Étape 6 : l'icône QR code (pour le téléphone) et la disquette (fichier .conf pour un ordinateur).")}
{box("attention", "Ce QR code, c'est la clé de votre maison", "<ul><li>Ne le <b>photographiez pas</b>, ne faites pas de capture d'écran, ne l'imprimez pas.</li><li>Ne l'envoyez <b>jamais</b> par e-mail, SMS, WhatsApp, Messenger…</li><li>Scannez-le directement avec l'application, puis <b>fermez la fenêtre</b>.</li></ul>")}
{box("info", "Sans ordinateur ? Avec l'application « Free » sur le téléphone", "<p><span class='menu'>Mes équipements Freebox</span> <span class='arrow'>➜</span> <span class='menu'>Serveur internet</span> <span class='arrow'>➜</span> <span class='menu'>Paramètres réseau avancés</span> <span class='arrow'>➜</span> <span class='menu'>Serveur VPN</span> : activez <b>WireGuard</b>, puis <span class='menu'>Appareils</span> <span class='arrow'>➜</span> <span class='menu'>Ajouter un appareil</span>. Dans <span class='menu'>Fichiers</span>, téléchargez la configuration de l'appareil et importez-la avec « Importer un fichier .conf » (étape 9). Les libellés peuvent varier selon la version de l'application.</p>")}
{box("ok", "Vérification avant de continuer", "<ul class='checklist'><li>WireGuard est <b>coché « Activer »</b> et vous avez cliqué sur <b>Appliquer</b>.</li><li>Un <b>utilisateur WireGuard</b> existe pour ce téléphone.</li><li>Son <b>QR code est affiché</b> à l'écran de l'ordinateur.</li></ul>")}
"""))

    # ------------------------------------------------------------------ partie 2 : Android
    h.append(part(2, "Android : l'application VPN Maison", "Installation de l'APK, import du QR code, connexion. Environ 10 minutes.").replace('class="part"', 'class="part android"'))
    h.append(step(7, "Mettre le fichier APK sur le téléphone", f"""
<p>Une application Android installée sans passer par le Play Store se présente sous la forme d'un fichier <b>APK</b>.
Celui de VPN Maison s'appelle <code>{APK}</code> (environ 4 Mo). Choisissez <b>une</b> de ces méthodes :</p>
<ul><li><b>Depuis le téléphone</b> : ouvrez la page GitHub du projet (<code>github.com/{REPO}</code>, dossier <code>vpn-freebox/dist</code>),
touchez le fichier <code>{APK}</code> puis le bouton de téléchargement (« Download raw file », flèche vers le bas).</li>
<li><b>Par câble USB</b> : branchez le téléphone à l'ordinateur, choisissez « Transfert de fichiers », copiez l'APK dans le dossier <b>Download</b>.</li></ul>
{box("attention", "Uniquement depuis cette source", "<p>N'installez jamais un APK « VPN » trouvé sur un site inconnu : une fausse application VPN pourrait voir tout votre trafic.</p>")}
"""))
    h.append(step(8, "Installer l'application", f"""
<p>Ouvrez le fichier (notification « Téléchargement terminé », ou application <b>Fichiers</b> <span class="arrow">➜</span> <b>Téléchargements</b>).
La première fois, Android bloque l'installation <span class="b-red" style="width:18px;height:18px;font-size:9pt">1</span> : touchez <b>Paramètres</b>,
activez <b>Autoriser cette source</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">2</span>, revenez en arrière, puis touchez <b>Installer</b>
<span class="b-red" style="width:18px;height:18px;font-size:9pt">3</span>.</p>
{fig("android_install", "Étape 8 : autoriser l'installation puis installer « VPN Maison ».")}
{box("info", "Message de Google Play Protect ?", "<p>Une application installée hors du Play Store peut afficher « Application non sécurisée bloquée » ou « Appli inconnue ». C'est normal pour une application personnelle : touchez <b>Plus de détails</b> <span class='arrow'>➜</span> <b>Installer quand même</b>. Ne le faites que pour ce fichier.</p><p><b>À savoir</b> : Google a annoncé qu'à partir de 2027 (déploiement mondial) l'installation hors Play Store demandera un développeur « vérifié » ou une procédure avancée pour les utilisateurs expérimentés. Si un jour l'installation est refusée, utilisez l'application officielle <b>WireGuard</b> du Play Store : la procédure est la même que pour l'iPhone (partie 3).</p>")}
"""))
    h.append(step(9, "Importer la configuration (QR code)", f"""
<p>Ouvrez <b>VPN Maison</b> et touchez <span class="btn">Scanner le QR code</span> <span class="b-red" style="width:18px;height:18px;font-size:9pt">1</span>.
Autorisez la caméra <b>« Pendant l'utilisation de l'appli »</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">2</span>,
puis visez le QR code affiché sur l'ordinateur <span class="b-red" style="width:18px;height:18px;font-size:9pt">3</span>. Le message
<b>« Configuration importée ✔ »</b> confirme que c'est bon : vous pouvez fermer la fenêtre du QR code sur l'ordinateur.</p>
{fig("android_import", "Étape 9 : scanner le QR code affiché par Freebox OS.")}
{box("astuce", "Le scan ne marche pas ?", "<ul><li>Agrandissez le QR code sur l'ordinateur (<span class='key'>Ctrl</span> + <span class='key'>+</span>) et éloignez un peu le téléphone.</li><li>Message « n'est pas une configuration WireGuard » : vous avez scanné un autre QR code (Wi-Fi par exemple). Utilisez l'icône QR de l'<b>utilisateur WireGuard</b>.</li><li>Autre solution : téléchargez le fichier avec la <b>disquette</b> (étape 6), mettez-le sur le téléphone et touchez « Importer un fichier .conf ».</li></ul>")}
"""))
    h.append(step(10, "Se connecter", f"""
<p>Touchez le gros bouton gris <b>Connecter</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">1</span>.
La première fois, Android affiche <b>« Demande de connexion »</b> : touchez <b>OK</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">2</span>.
Le bouton devient <b style="color:#1E8E3E">vert</b>, l'écran indique <b>« Connecté à ma Freebox »</b> et une petite <b>clé</b> apparaît en haut de l'écran
<span class="b-red" style="width:18px;height:18px;font-size:9pt">3</span>.</p>
{fig("android_connect", "Étape 10 : connexion et autorisation VPN d'Android (une seule fois).")}
{box("ok", "« ✔ La Freebox répond »", "<p>Cette ligne prouve que votre box a bien répondu à travers le tunnel. Si au bout de 15 secondes l'application affiche <b>« La Freebox ne répond pas »</b>, suivez les conseils qu'elle donne ou la partie 5.</p>")}
"""))
    h.append(step(11, "Faire le vrai test en 4G", f"""
<p><b>Coupez le Wi-Fi du téléphone</b> (pour être vraiment « à l'extérieur »), vérifiez que le bouton est vert, puis dans la carte
<b>« Tester l'accès à ma Freebox »</b> touchez <b>Lancer le test</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">A</span>.
Résultat attendu : <b style="color:#1E8E3E">« Bravo ! Votre box répond : Freebox … »</b>.</p>
{fig("android_extras", "Étapes 11 et 12 : le test (A), la tuile des réglages rapides (B) et le VPN permanent (C).")}
"""))
    h.append(step(12, "Options pratiques (facultatif)", """
<ul><li><b>Tuile « VPN Maison »</b> (B) : glissez deux fois vers le bas depuis le haut de l'écran, touchez le crayon ✎ et faites glisser la tuile dans vos réglages rapides. Un appui = connexion / déconnexion, sans ouvrir l'application.</li>
<li><b>VPN permanent</b> (C) : <span class="menu">Paramètres</span> <span class="arrow">➜</span> <span class="menu">Réseau et Internet</span> (ou « Connexions ») <span class="arrow">➜</span> <span class="menu">VPN</span> <span class="arrow">➜</span> roue dentée à côté de « VPN Maison » <span class="arrow">➜</span> <b>VPN permanent</b>. Le téléphone reste toujours connecté à la maison.</li>
<li><b>« Tout Internet passe par ma Freebox »</b> (interrupteur dans l'application) :
<ul><li><b>Activé</b> (par défaut, recommandé sur un Wi-Fi public) : tout votre trafic est chiffré jusqu'à la maison.</li>
<li><b>Désactivé</b> : seul l'accès à la maison (box, NAS, imprimante…) passe par le VPN ; le reste va directement sur Internet. Utile si votre box a un débit montant faible (ADSL).</li></ul></li></ul>
""" + box("ok", "Vérification Android", "<ul class='checklist'><li>Le bouton est <b>vert</b> et la <b>clé</b> est visible en haut.</li><li>Wi-Fi coupé, le test affiche <b>« Bravo ! Votre box répond »</b>.</li></ul>")))

    # ------------------------------------------------------------------ partie 3 : iPhone
    h.append(part(3, "iPhone : l'application WireGuard", "Gratuite, officielle, même moteur que VPN Maison. Environ 5 minutes.").replace('class="part"', 'class="part ios"'))
    h.append(box("info", "Pourquoi pas l'application « VPN Maison » sur iPhone ?", """<p>Apple n'autorise l'installation d'une application VPN en dehors de l'App Store qu'avec un <b>compte Apple Developer payant (99 $ par an)</b> et un <b>Mac</b>
(la fonction « Network Extension » n'est pas disponible avec un compte Apple gratuit). Le code de VPN Maison pour iPhone est fourni (annexe A), mais pour un usage personnel
l'application officielle <b>WireGuard</b> est gratuite, open source, et utilise <b>exactement le même moteur</b>. C'est le choix le plus simple et le plus sûr.</p>"""))
    h.append(step(13, "Installer WireGuard", """
<p>Ouvrez l'<b>App Store</b>, onglet <b>Rechercher</b>, tapez <code>wireguard</code>. Vérifiez l'éditeur : <b>WireGuard Development Team</b>
(icône rouge). Touchez <b>Obtenir</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">1</span>.</p>
"""))
    h.append(step(14, "Ajouter le tunnel avec le QR code", f"""
<p>Ouvrez <b>WireGuard</b>, touchez <b>+</b> en haut à droite (ou « Ajouter un tunnel ») <span class="b-red" style="width:18px;height:18px;font-size:9pt">2</span>,
puis <b>« Créer à partir d'un code QR »</b>. Autorisez la caméra, visez le QR code de l'ordinateur, donnez le nom <code>Freebox</code> au tunnel et enregistrez.
iOS demande alors l'autorisation d'ajouter une configuration VPN : touchez <b>Autoriser</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">3</span>
puis confirmez avec Face ID, Touch ID ou votre code.</p>
{fig("ios_install", "Étapes 13 et 14 : installer WireGuard, créer le tunnel depuis le QR code, autoriser.")}
"""))
    h.append(step(15, "Se connecter", f"""
<p>Touchez l'interrupteur à droite de <b>Freebox</b> <span class="b-red" style="width:18px;height:18px;font-size:9pt">4</span> : il devient
<b style="color:#1E8E3E">vert</b> et le petit logo <b>VPN</b> apparaît en haut de l'écran <span class="b-red" style="width:18px;height:18px;font-size:9pt">5</span>.
Vous pouvez aussi l'activer dans <span class="menu">Réglages</span> <span class="arrow">➜</span> <span class="menu">VPN</span>.</p>
{fig("ios_connect", "Étapes 15 et 16 : activer le tunnel, puis tester dans Safari en 4G.")}
""", keep=True))
    h.append(step(16, "Tester en 4G", """
<p>Coupez le Wi-Fi (Centre de contrôle), puis ouvrez <b>Safari</b> et allez sur <code>mafreebox.freebox.fr</code>
<span class="b-red" style="width:18px;height:18px;font-size:9pt">6</span>. Si la page de connexion de Freebox OS s'affiche, <b>tout fonctionne</b>.</p>
""" + box("astuce", "Connexion automatique hors de la maison (facultatif)", "<p>Dans WireGuard, touchez <b>Freebox</b> <span class='arrow'>➜</span> <b>Modifier</b> <span class='arrow'>➜</span> <b>Activation à la demande</b> : cochez <b>Données cellulaires</b> et <b>Wi-Fi</b>, puis excluez le Wi-Fi de votre maison. Le VPN s'allumera tout seul dès que vous sortirez.</p>") + box("ok", "Vérification iPhone", "<ul class='checklist'><li>L'interrupteur est <b>vert</b> et « VPN » est affiché en haut.</li><li>Wi-Fi coupé, <code>mafreebox.freebox.fr</code> s'ouvre dans Safari.</li></ul>")))

    # ------------------------------------------------------------------ partie 4
    h.append(part(4, "Tester comme il faut", "Le piège n°1 : tester depuis le Wi-Fi de la maison."))
    h.append("""
<p>Quand le téléphone est sur le Wi-Fi de la Freebox, il est <b>déjà</b> à la maison : un test fait là ne prouve rien (et peut même échouer alors que tout est bon).
Faites toujours les tests <b>en 4G/5G, Wi-Fi coupé</b>.</p>
<table><tr><th style="width:5%">#</th><th style="width:40%">Test</th><th>Résultat attendu</th></tr>
<tr><td>1</td><td>Regarder l'état du VPN</td><td>Bouton vert (VPN Maison) ou interrupteur vert (WireGuard), clé ou « VPN » en haut de l'écran.</td></tr>
<tr><td>2</td><td>Ouvrir <code>mafreebox.freebox.fr</code> dans le navigateur du téléphone</td><td>La page de connexion de Freebox OS s'affiche.</td></tr>
<tr><td>3</td><td>Chercher « quelle est mon adresse IP » sur Internet</td><td>L'adresse affichée est <b>celle de votre box</b> (la même qu'à l'étape 2). <i>Seulement si « Tout Internet passe par ma Freebox » est activé.</i></td></tr>
<tr><td>4</td><td>Ouvrir un appareil de la maison (NAS, caméra…) avec son adresse habituelle (ex. <code>192.168.1.x</code>)</td><td>Il répond comme si vous étiez chez vous.</td></tr></table>
""")

    # ------------------------------------------------------------------ partie 5
    h.append(part(5, "En cas de problème", "Cherchez votre symptôme dans la colonne de gauche."))
    h.append("""
<table><tr><th style="width:28%">Ce que vous voyez</th><th style="width:30%">Cause probable</th><th>Solution</th></tr>
<tr><td>« La Freebox ne répond pas » / connecté mais aucune page ne s'ouvre</td><td>Serveur WireGuard non activé, ou port hors de votre plage (IPv4 partagée)</td><td>Refaites l'étape 4 (Activer + Appliquer). Ne changez pas le port. Si cela persiste : IP fixe V4 full-stack (encadré de l'étape 2), puis recréez l'utilisateur et réimportez le QR code.</td></tr>
<tr><td>Ça marche en 4G mais pas sur le Wi-Fi d'un hôtel / d'une entreprise</td><td>Ce réseau bloque les VPN</td><td>Utilisez la 4G (partage de connexion si besoin). Rien à corriger de votre côté.</td></tr>
<tr><td>Ça ne marche pas sur le Wi-Fi de la maison</td><td>Test inutile à la maison</td><td>Normal dans certains cas : testez en 4G, Wi-Fi coupé (partie 4).</td></tr>
<tr><td>« Ce n'est pas une configuration WireGuard »</td><td>Mauvais QR code scanné</td><td>Utilisez l'icône QR code de l'<b>utilisateur WireGuard</b> (étape 6), pas un QR code Wi-Fi.</td></tr>
<tr><td>Installation de l'APK impossible</td><td>Source non autorisée ou Play Protect</td><td>Étape 8 : « Autoriser cette source », puis « Installer quand même ». Sinon, application WireGuard du Play Store.</td></tr>
<tr><td>Un téléphone se déconnecte quand l'autre se connecte</td><td>La même configuration sur deux appareils</td><td>Créez un utilisateur par appareil (étape 5) et importez chaque QR code sur son appareil.</td></tr>
<tr><td>Ça marchait, et plus du tout depuis hier</td><td>Utilisateur supprimé/recréé, box réinitialisée ou adresse IP changée</td><td>Vérifiez l'étape 4, puis réimportez un QR code à jour (menu ⋮ <span class="arrow">➜</span> « Remplacer la configuration »).</td></tr>
<tr><td>Tout est lent</td><td>Débit montant de la box limité (ADSL), ou Wi-Fi public saturé</td><td>Désactivez « Tout Internet passe par ma Freebox » (étape 12) pour n'envoyer que le trafic de la maison dans le tunnel.</td></tr>
<tr><td>« Android a refusé de démarrer le VPN »</td><td>Un autre VPN est actif ou « permanent »</td><td><span class="menu">Paramètres</span> <span class="arrow">➜</span> <span class="menu">VPN</span> : désactivez l'autre VPN, puis réessayez.</td></tr></table>
""" + box("astuce", "Toujours bloqué ?", "<p>Faites un essai avec l'application officielle <b>WireGuard</b> (Play Store / App Store) et le même QR code : si elle ne marche pas non plus, le problème vient de la box ou du réseau (étapes 2 et 4), pas de l'application.</p>"))

    # ------------------------------------------------------------------ partie 6
    h.append(part(6, "Sécurité : les bons réflexes"))
    h.append("""
<div class="two">
<div class="card"><h4>À faire</h4><ul>
<li><b>Un utilisateur par appareil</b>, avec un nom clair (<code>telephone-marie</code>).</li>
<li><b>Téléphone perdu ou volé ?</b> Supprimez tout de suite son utilisateur dans Freebox OS (icône corbeille, étape 6) : sa configuration devient inutilisable.</li>
<li>Gardez un <b>code de verrouillage</b> sur le téléphone.</li>
<li>Faites les <b>mises à jour</b> de Freebox OS et du téléphone.</li>
<li>Choisissez un <b>mot de passe Freebox OS solide</b> (12 caractères ou plus).</li></ul></div>
<div class="card"><h4>À ne jamais faire</h4><ul>
<li>Envoyer le QR code ou le fichier <code>.conf</code> par e-mail, SMS ou messagerie.</li>
<li>Prendre le QR code en photo ou en capture d'écran (elles partent souvent dans le cloud).</li>
<li>Donner votre configuration à un ami : créez-lui son propre utilisateur, que vous pourrez supprimer.</li>
<li>Installer une application VPN venant d'une source inconnue.</li></ul></div></div>
""" + box("info", "Accès à distance à Freebox OS", "<p>Si vous n'utilisez Freebox OS à distance <b>que</b> via le VPN, vous pouvez désactiver l'« Accès à distance » de Freebox OS (Paramètres › Mode avancé). Moins de portes ouvertes = moins de risques.</p>"))

    # ------------------------------------------------------------------ annexes
    h.append(part("A", "Pourquoi pas de fichier .ipa prêt à installer ?").replace("PARTIE A", "ANNEXE A").replace('class="part"', 'class="part annexe"'))
    h.append("""
<p>Sur iPhone, une application VPN a besoin d'une autorisation spéciale d'Apple (« Network Extension »). Elle n'est accordée qu'aux membres du
<b>programme Apple Developer (99 $ par an)</b>, et l'application doit être signée avec leur certificat. Un fichier <code>.ipa</code> non signé ne peut pas
être installé sur un iPhone normal, et les outils d'installation avec un identifiant Apple gratuit (AltStore, Sideloadly…) ne donnent pas cette autorisation.</p>
<h4>Ce qui est fourni</h4>
<ul><li>Le <b>code source complet</b> de VPN Maison pour iPhone : dossier <code>vpn-freebox/ios</code> (SwiftUI + extension réseau WireGuardKit officielle).</li>
<li>Une <b>compilation automatique sur Mac</b> par GitHub Actions (onglet « Actions » du dépôt) qui produit un <code>.ipa</code> <b>non signé</b>.</li></ul>
<h4>Pour l'installer sur votre iPhone (si un jour vous avez un Mac et le compte payant)</h4>
<ol><li>Sur le Mac : installez <b>Xcode</b> (App Store) et <b>Homebrew</b>, puis dans le Terminal : <code>brew install xcodegen go</code>.</li>
<li>Téléchargez le dépôt, puis : <code>cd vpn-freebox/ios</code> et <code>xcodegen generate</code>.</li>
<li>Ouvrez <code>VPNMaison.xcodeproj</code>. Pour les cibles <b>VPNMaison</b> et <b>PacketTunnel</b>, onglet <b>Signing &amp; Capabilities</b> : choisissez votre équipe (Team).</li>
<li>Branchez l'iPhone, activez <span class="menu">Réglages</span> <span class="arrow">➜</span> <span class="menu">Confidentialité et sécurité</span> <span class="arrow">➜</span> <span class="menu">Mode développeur</span>.</li>
<li>Dans Xcode, choisissez votre iPhone en haut et cliquez sur ▶ (Run).</li></ol>
""" + box("attention", "Honnêtement", "<p>Pour un usage personnel, payer 99 $ par an pour retrouver ce que fait déjà gratuitement l'application officielle WireGuard n'a pas d'intérêt. Le projet iPhone est fourni pour être complet, et n'a pas pu être essayé sur un vrai iPhone.</p>"))
    h.append(part("B", "Transparence : ce que fait l'application VPN Maison").replace("PARTIE B", "ANNEXE B").replace('class="part"', 'class="part annexe"'))
    h.append(f"""
<table><tr><th style="width:30%">Question</th><th>Réponse</th></tr>
<tr><td>Quel moteur VPN ?</td><td>La bibliothèque <b>officielle</b> WireGuard pour Android (<code>com.wireguard.android:tunnel</code>, WireGuard LLC, licence Apache 2.0). L'application n'a pas « inventé » son propre chiffrement.</td></tr>
<tr><td>Où est rangée ma configuration ?</td><td>Uniquement dans la mémoire privée de l'application, sur le téléphone. Exclue des sauvegardes cloud et des transferts vers un nouveau téléphone.</td></tr>
<tr><td>Quelles autorisations ?</td><td><b>Internet</b> (pour le VPN), <b>caméra</b> (seulement pour lire le QR code), et l'autorisation <b>VPN</b> d'Android que vous acceptez à l'étape 10.</td></tr>
<tr><td>Envoie-t-elle des données ?</td><td>Non : ni publicité, ni statistiques, ni serveur tiers. Le seul échange en dehors du tunnel est la connexion chiffrée à <b>votre</b> Freebox.</td></tr>
<tr><td>Et le bouton « Lancer le test » ?</td><td>Il lit la page publique <code>http://mafreebox.freebox.fr/api_version</code> de votre box, à travers le tunnel. Elle ne renvoie que le modèle de la box ; aucun mot de passe n'est envoyé.</td></tr>
<tr><td>Code source</td><td><code>github.com/{REPO}</code>, dossier <code>vpn-freebox</code>.</td></tr>
<tr><td>Ce qui a été vérifié</td><td>L'APK a été compilé et signé, et sa logique de lecture de configuration est couverte par des tests automatiques. Il n'a pas pu être essayé sur un téléphone réel avant la livraison : signalez tout souci.</td></tr></table>
<p style="margin-top:14px;color:#5F6368;font-size:9pt">Les noms de menus de Freebox OS et de l'application Free proviennent de la documentation disponible fin 2026 ; Free peut les modifier.
« Freebox » est une marque de Free ; ce tutoriel est indépendant et non affilié.</p>
""")

    html = f"""<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>VPN Maison — Tutoriel Freebox</title>
<style>{CSS}</style></head><body>{''.join(h)}</body></html>"""
    (HERE / "tutoriel.html").write_text(html, encoding="utf-8")
    print("tutoriel.html écrit")


if __name__ == "__main__":
    build()

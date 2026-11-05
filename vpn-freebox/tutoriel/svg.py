"""Petites briques SVG pour dessiner les schémas d'écran du tutoriel.

Toutes les maquettes sont des SCHÉMAS SIMPLIFIÉS (pas des captures d'écran) :
les couleurs et la disposition exactes de Freebox OS / Android / iOS peuvent varier.
"""
from html import escape

RED = "#E3262F"
BLUE = "#0B3D91"
LIGHT_BLUE = "#E8F0FE"
GREEN = "#1E8E3E"
AMBER = "#F29900"
GREY = "#5F6368"
LINE = "#C9CDD3"
BG = "#F4F6F9"

_uid = [0]


def uid(prefix="m"):
    _uid[0] += 1
    return f"{prefix}{_uid[0]}"


def svg(width, height, body, label=""):
    mid = uid("arrow")
    defs = (
        f'<defs><marker id="{mid}" viewBox="0 0 10 10" refX="7" refY="5" markerWidth="5" markerHeight="5" '
        f'orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{RED}"/></marker></defs>'
    )
    body = body.replace("__ARROW__", mid)
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" role="img" '
        f'aria-label="{escape(label)}" font-family="Inter, DejaVu Sans, sans-serif">{defs}{body}</svg>'
    )


def text(x, y, s, size=13, weight=400, fill="#202124", anchor="start", italic=False, family=None):
    fam = f' font-family="{family}"' if family else ""
    st = ' font-style="italic"' if italic else ""
    return (
        f'<text x="{x}" y="{y}" font-size="{size}" font-weight="{weight}" fill="{fill}" '
        f'text-anchor="{anchor}"{st}{fam}>{escape(s)}</text>'
    )


def lines(x, y, rows, size=13, weight=400, fill="#202124", anchor="start", gap=None):
    gap = gap or size * 1.35
    return "".join(text(x, y + i * gap, r, size, weight, fill, anchor) for i, r in enumerate(rows))


def rect(x, y, w, h, fill="#fff", stroke=LINE, r=6, sw=1, extra=""):
    return f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}" {extra}/>'


def button(x, y, w, h, label, kind="primary", size=13, r=6):
    if kind == "primary":
        return rect(x, y, w, h, BLUE, BLUE, r) + text(x + w / 2, y + h / 2 + size * 0.36, label, size, 600, "#fff", "middle")
    if kind == "green":
        return rect(x, y, w, h, GREEN, GREEN, r) + text(x + w / 2, y + h / 2 + size * 0.36, label, size, 600, "#fff", "middle")
    if kind == "text":
        return text(x + w / 2, y + h / 2 + size * 0.36, label, size, 600, BLUE, "middle")
    return rect(x, y, w, h, "#fff", BLUE, r, 1.5) + text(x + w / 2, y + h / 2 + size * 0.36, label, size, 600, BLUE, "middle")


def field(x, y, w, h, value, label=None, mono=False, dropdown=False):
    out = ""
    if label:
        out += text(x, y - 6, label, 11, 500, GREY)
    out += rect(x, y, w, h, "#fff", "#9AA0A6", 4)
    out += text(x + 8, y + h / 2 + 4.5, value, 12.5, 500, "#202124", family="JetBrains Mono, monospace" if mono else None)
    if dropdown:
        out += f'<path d="M{x + w - 18},{y + h / 2 - 3} l6,6 l6,-6" fill="none" stroke="{GREY}" stroke-width="2"/>'
    return out


def checkbox(x, y, checked=True, label=""):
    out = rect(x, y, 16, 16, BLUE if checked else "#fff", BLUE, 3, 1.5)
    if checked:
        out += f'<path d="M{x + 3.5},{y + 8.5} l3.5,3.5 l6,-7" fill="none" stroke="#fff" stroke-width="2.2"/>'
    if label:
        out += text(x + 24, y + 13, label, 13, 500)
    return out


def toggle(x, y, on=True, color=GREEN):
    bg = color if on else "#BDC1C6"
    cx = x + 30 if on else x + 12
    return rect(x, y, 42, 24, bg, bg, 12) + f'<circle cx="{cx}" cy="{y + 12}" r="9.5" fill="#fff"/>'


def highlight(x, y, w, h, r=8, pad=5):
    return (
        f'<rect x="{x - pad}" y="{y - pad}" width="{w + 2 * pad}" height="{h + 2 * pad}" rx="{r}" '
        f'fill="none" stroke="{RED}" stroke-width="3.5"/>'
    )


def arrow(x1, y1, x2, y2, bend=0):
    """Flèche rouge épaisse, éventuellement courbée (bend en pixels)."""
    if bend:
        mx, my = (x1 + x2) / 2, (y1 + y2) / 2
        dx, dy = x2 - x1, y2 - y1
        length = (dx * dx + dy * dy) ** 0.5 or 1
        cx, cy = mx - dy / length * bend, my + dx / length * bend
        d = f"M{x1},{y1} Q{cx},{cy} {x2},{y2}"
    else:
        d = f"M{x1},{y1} L{x2},{y2}"
    return f'<path d="{d}" fill="none" stroke="{RED}" stroke-width="4" stroke-linecap="round" marker-end="url(#__ARROW__)"/>'


def badge(x, y, n, r=14):
    return (
        f'<circle cx="{x}" cy="{y}" r="{r}" fill="{RED}" stroke="#fff" stroke-width="2.5"/>'
        + text(x, y + r * 0.38, str(n), r * 1.1, 800, "#fff", "middle")
    )


def note(x, y, rows, anchor="start", size=12.5):
    """Petite annotation rouge à côté d'une flèche."""
    return lines(x, y, rows, size, 700, RED, anchor)


# --------------------------------------------------------------------------- cadres


def browser(x, y, w, h, url, body="", title="Freebox OS"):
    out = f'<g transform="translate({x},{y})">'
    out += rect(0, 0, w, h, "#fff", "#9AA0A6", 10, 1.5)
    out += f'<path d="M0,10 a10,10 0 0 1 10,-10 h{w - 20} a10,10 0 0 1 10,10 v32 h-{w} z" fill="#E8EAED"/>'
    for i, c in enumerate(["#FF5F57", "#FEBC2E", "#28C840"]):
        out += f'<circle cx="{16 + i * 16}" cy="15" r="5" fill="{c}"/>'
    out += rect(70, 6, 150, 18, "#fff", "none", 5) + text(80, 19, title, 10.5, 500, GREY)
    out += rect(12, 30, w - 24, 22, "#fff", "#DADCE0", 11)
    out += f'<path d="M24,37 v8 h8 v-8 z M25.5,37 v-3 a2.5,2.5 0 0 1 5,0 v3" fill="none" stroke="{GREY}" stroke-width="1.3"/>'
    out += text(40, 45.5, url, 12.5, 500, "#202124")
    out += f'<g transform="translate(0,60)">{body}</g></g>'
    return out


def window(x, y, w, h, title, body=""):
    """Fenêtre interne de Freebox OS (schéma)."""
    out = f'<g transform="translate({x},{y})">'
    out += rect(0, 0, w, h, "#fff", "#9AA0A6", 8, 1.2)
    out += f'<path d="M0,8 a8,8 0 0 1 8,-8 h{w - 16} a8,8 0 0 1 8,8 v20 h-{w} z" fill="{BLUE}"/>'
    out += text(12, 19, title, 12.5, 600, "#fff")
    out += f'<path d="M{w - 22},9 l10,10 M{w - 12},9 l-10,10" stroke="#fff" stroke-width="1.8"/>'
    out += f'<g transform="translate(0,28)">{body}</g></g>'
    return out


def phone(x, y, body="", platform="android", w=230, h=470, key=False, dim=False, time="10:24"):
    """Téléphone (schéma). L'écran utile commence à (10, 34) dans le repère du téléphone."""
    out = f'<g transform="translate({x},{y})">'
    out += rect(0, 0, w, h, "#1F2124", "#1F2124", 30)
    out += rect(8, 8, w - 16, h - 16, "#fff", "none", 23)
    out += f'<clipPath id="{(cid := uid("clip"))}"><rect x="8" y="8" width="{w - 16}" height="{h - 16}" rx="23"/></clipPath>'
    out += f'<g clip-path="url(#{cid})">'
    if platform == "ios":
        out += rect(w / 2 - 40, 14, 80, 20, "#1F2124", "none", 10)
        out += text(30, 29, time, 11.5, 700)
    else:
        out += f'<circle cx="{w / 2}" cy="20" r="5" fill="#1F2124"/>'
        out += text(22, 26, time, 11, 600)
    # icônes de droite : réseau + batterie
    out += f'<path d="M{w - 66},27 l10,-10 v10 z" fill="#202124"/>'
    out += rect(w - 48, 18, 20, 10, "none", "#202124", 2, 1.2) + rect(w - 46, 20, 13, 6, "#202124", "none", 1)
    if key:
        out += highlight(w - 92, 15, 18, 15, 4, 3)
        out += f'<g transform="translate({w - 91},16)"><circle cx="5" cy="6.5" r="4" fill="none" stroke="#202124" stroke-width="1.8"/><path d="M9,6.5 h7 v3 M13.5,6.5 v2.5" stroke="#202124" stroke-width="1.8" fill="none"/></g>'
    out += f'<g transform="translate(8,36)">{body}</g>'
    if dim:
        out += rect(8, 8, w - 16, h - 16, "rgba(0,0,0,0.38)", "none", 0)
    out += "</g></g>"
    return out


def dialog(x, y, w, h, title, rows, buttons, size=12.5):
    """Boîte de dialogue système. buttons = [(label, highlighted?)] alignés à droite."""
    out = f'<g transform="translate({x},{y})">' + rect(2, 4, w, h, "rgba(0,0,0,0.18)", "none", 16, 0)
    out += rect(0, 0, w, h, "#fff", "none", 16, 0)
    out += text(16, 28, title, 15, 700)
    out += lines(16, 52, rows, size, 400, "#3C4043")
    bx = w - 14
    for label, hot in reversed(buttons):
        bw = 9 + len(label) * 7.6
        bx -= bw
        out += text(bx + bw / 2, h - 18, label, 13, 700, BLUE, "middle")
        if hot:
            out += highlight(bx, h - 34, bw, 24, 6, 2)
        bx -= 8
    return out + "</g>"


def fake_qr(x, y, size, seed=7):
    """QR code DÉCORATIF (illisible) : il ne contient aucune configuration."""
    import random

    rnd = random.Random(seed)
    n = 25
    c = size / n
    out = rect(x - 6, y - 6, size + 12, size + 12, "#fff", "#DADCE0", 4)
    for i in range(n):
        for j in range(n):
            finder = (i < 7 and j < 7) or (i < 7 and j >= n - 7) or (i >= n - 7 and j < 7)
            if finder:
                ii, jj = i % (n - 7) if i >= n - 7 else i, j % (n - 7) if j >= n - 7 else j
                on = ii in (0, 6) or jj in (0, 6) or (2 <= ii <= 4 and 2 <= jj <= 4)
            else:
                on = rnd.random() < 0.48
            if on:
                out += f'<rect x="{x + j * c:.2f}" y="{y + i * c:.2f}" width="{c + 0.3:.2f}" height="{c + 0.3:.2f}" fill="#111"/>'
    return out


# Icônes simples -------------------------------------------------------------------


def icon_floppy(x, y, s=18, color=GREY):
    return (
        f'<g transform="translate({x},{y}) scale({s / 24})"><path d="M3,3 h14 l4,4 v14 h-18 z" fill="none" stroke="{color}" stroke-width="2"/>'
        f'<rect x="7" y="3" width="8" height="6" fill="{color}"/><rect x="6" y="13" width="12" height="6" fill="none" stroke="{color}" stroke-width="1.6"/></g>'
    )


def icon_qr(x, y, s=18, color=GREY):
    g = f'<g transform="translate({x},{y}) scale({s / 24})" fill="{color}">'
    for ox, oy in ((2, 2), (14, 2), (2, 14)):
        g += f'<path d="M{ox},{oy} h8 v8 h-8 z M{ox + 2},{oy + 2} v4 h4 v-4 z" fill-rule="evenodd"/>'
    g += '<rect x="14" y="14" width="3" height="3"/><rect x="19" y="19" width="3" height="3"/><rect x="19" y="14" width="3" height="3"/><rect x="14" y="19" width="3" height="3"/>'
    return g + "</g>"


def icon_trash(x, y, s=18, color=GREY):
    return (
        f'<g transform="translate({x},{y}) scale({s / 24})" fill="none" stroke="{color}" stroke-width="2">'
        '<path d="M4,6 h16 M9,6 v-2 h6 v2 M6,6 l1,15 h10 l1,-15"/></g>'
    )


def icon_power(cx, cy, r=26, color="#fff"):
    return (
        f'<path d="M{cx - r * 0.55},{cy - r * 0.62} a{r * 0.82},{r * 0.82} 0 1 0 {r * 1.1},0" fill="none" stroke="{color}" stroke-width="{r * 0.18}" stroke-linecap="round"/>'
        f'<path d="M{cx},{cy - r * 0.95} v{r * 0.75}" stroke="{color}" stroke-width="{r * 0.18}" stroke-linecap="round"/>'
    )


def icon_shield(cx, cy, s=1.0, fill=BLUE, check=True):
    out = f'<g transform="translate({cx},{cy}) scale({s})"><path d="M0,-30 L-24,-20 v16 c0,16 10,30 24,34 c14,-4 24,-18 24,-34 v-16 z" fill="{fill}"/>'
    if check:
        out += '<path d="M-10,0 l7,7 l13,-14" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>'
    return out + "</g>"


def icon_house(cx, cy, s=1.0, fill=BLUE):
    return (
        f'<g transform="translate({cx},{cy}) scale({s})"><path d="M0,-26 L-28,-2 h7 v26 h14 v-14 h14 v14 h14 v-26 h7 z" fill="{fill}"/></g>'
    )

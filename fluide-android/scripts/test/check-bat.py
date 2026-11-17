#!/usr/bin/env python3
"""Vérifications statiques de fluide.bat : les pièges classiques de cmd.exe.

Ne remplace pas une exécution sous Windows, mais attrape les erreurs qui cassent un script
batch sans prévenir. Lancement : python3 scripts/test/check-bat.py scripts/fluide.bat
"""
import re
import sys


def strip_quoted(s):
    """Remplace le contenu entre guillemets par des X (cmd n'y interprète rien)."""
    out, q = [], False
    for ch in s:
        if ch == '"':
            q = not q
            out.append(ch)
        else:
            out.append('X' if q else ch)
    return ''.join(out)


def main(path):
    raw = open(path, 'rb').read()
    problems = []

    def bad(n, msg):
        problems.append(f"ligne {n} : {msg}")

    try:
        raw.decode('ascii')
    except UnicodeDecodeError as e:
        bad(raw[:e.start].count(b'\n') + 1, "caractère non ASCII (mal affiché par cmd selon la page de code)")
    lf = raw.count(b'\n')
    if raw.count(b'\r\n') != lf:
        problems.append(f"fins de ligne : {lf - raw.count(b'\r\n')} lignes sans CRLF (goto/call peuvent se perdre)")

    lines = raw.decode('ascii', errors='replace').replace('\r\n', '\n').split('\n')
    labels = {}
    targets = []
    depth = 0
    block_sets = []  # variables affectées dans chaque bloc ouvert

    for n, line in enumerate(lines, 1):
        s = line.strip()
        if not s or s.upper().startswith('REM') or s.startswith('::'):
            continue
        if s.startswith(':'):
            name = s[1:].split()[0].lower()
            if name in labels:
                bad(n, f"étiquette en double :{name}")
            labels[name] = n
            if depth:
                bad(n, "étiquette à l'intérieur d'un bloc entre parenthèses")
            continue
        for m in re.finditer(r'\b(?:goto|call)\s+:?([A-Za-z_][\w]*)', s, re.I):
            if not re.match(r'(?i)call\s+:', m.group(0)) and m.group(0).lower().startswith('call'):
                continue  # « call fichier.bat » : pas une étiquette
            targets.append((n, m.group(1).lower()))

        bare = strip_quoted(s)
        low = bare.lower()

        # Commande d'un for /f entre apostrophes : >, <, |, & hors guillemets doivent être échappés.
        fm = re.search(r"\bin\s*\('([^']*(?:'[^']*'[^']*)*)'\)", s, re.I)
        if fm:
            cmd = strip_quoted(fm.group(1))
            if re.search(r'(?<!\^)[<>|&]', cmd):
                bad(n, "for /f : >, <, | ou & non échappé (^) hors guillemets")

        # Texte affiché : caractères spéciaux.
        em = re.match(r'(?:>>?\s*"[^"]*"\s*)?echo[ .:](.*)$', s, re.I)
        if em:
            text = strip_quoted(em.group(1))
            if depth and re.search(r'(?<!\^)[()]', text):
                bad(n, "parenthèse dans un echo à l'intérieur d'un bloc (ferme le bloc)")
            if re.search(r'(?<!\^)[<>|&]', text):
                bad(n, "echo : >, <, | ou & non échappé")
            if '!' in re.sub(r'![A-Za-z_][\w:~,=\- ]*!', '', em.group(1)):
                bad(n, "echo : « ! » isolé (disparaît avec l'expansion différée)")
            rest = re.sub(r'%%|%[A-Za-z_]\w*%|%~?[a-z]*[0-9*]', '', em.group(1))
            if '%' in rest:
                bad(n, "echo : « % » isolé (à doubler)")

        # Variable affectée dans un bloc puis lue en %VAR% dans ce même bloc : valeur figée.
        if depth:
            for v in re.findall(r'\bset\s+(?:/a\s+)?"?([A-Za-z_]\w*)=', s, re.I):
                block_sets[-1].add(v.lower())
            for v in re.findall(r'%([A-Za-z_]\w*)%', s):
                if any(v.lower() in b for b in block_sets):
                    bad(n, f"%{v}% lu dans le bloc qui l'affecte : utiliser !{v}!")

        # Suivi des blocs (hors guillemets).
        for ch in bare:
            if ch == '(':
                depth += 1
                block_sets.append(set())
            elif ch == ')':
                if depth == 0:
                    bad(n, "parenthèse fermante sans bloc ouvert")
                else:
                    depth -= 1
                    inner = block_sets.pop()
                    if block_sets:
                        block_sets[-1] |= inner

    if depth:
        problems.append(f"{depth} bloc(s) entre parenthèses jamais fermé(s)")
    for n, t in targets:
        if t not in labels and t != 'eof':
            bad(n, f"étiquette introuvable : {t}")

    if problems:
        print(f"{path} : {len(problems)} problème(s)")
        for p in problems:
            print("  " + p)
        return 1
    print(f"{path} : OK ({len(lines)} lignes, {len(labels)} étiquettes, {len(targets)} sauts/appels vérifiés)")
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else 'scripts/fluide.bat'))

#!/usr/bin/env python3
"""Prueft die statische EMTF-Sammlung auf tote lokale Verweise.

Laeuft in "Stage Checks". Externe Links (http/https/mailto) werden nicht
abgefragt, nur Dateien, die wir selbst ausliefern: verlinkte PDFs, Bilder und
Unterseiten. So faellt vor dem Deployment auf, wenn eine Datei fehlt oder ein
Pfad nicht stimmt.

Bewusst geduldete Ausnahmen stehen in ALLOWED_MISSING und muessen begruendet
sein. Aufruf: python3 scripts/check-emtf-links.py [wurzelverzeichnis]
"""

from __future__ import annotations

import os
import re
import sys
import urllib.parse

# Praefixe von Verweisen, die fehlen duerfen.
ALLOWED_MISSING: dict[str, str] = {
    # Reste eines Word-HTML-Exports. Sie stehen ausschliesslich in
    # <!--[if gte mso 9]>-Bloecken und werden von Browsern nie geladen.
    "index-Dateien/": "Word-Exportreste, nur in MS-Office-Bedingungsbloecken",
    "Inhaltsverzeichnis-Dateien/": "Word-Exportreste, nur in MS-Office-Bedingungsbloecken",
    # Sammelbaende, die im uebergebenen Bestand fehlen (Stand 23.09.2026).
    # Die Einzelbeitraege dieser Jahrgaenge sind vollstaendig vorhanden.
    "./pdf/Komplett_EMTF_2001.pdf": "Sammelband nicht im Bestand",
    "./pdf/Komplett_EMTF_2003.pdf": "Sammelband nicht im Bestand",
}

LINK_PATTERN = re.compile(r'(?:href|src)\s*=\s*"([^"]+)"', re.IGNORECASE)
EXTERNAL_PATTERN = re.compile(r"^(https?:|mailto:|#|javascript:|data:)", re.IGNORECASE)


def is_allowed(target: str) -> bool:
    return any(target.startswith(prefix) for prefix in ALLOWED_MISSING)


def main(root: str) -> int:
    if not os.path.isdir(root):
        print(f"Verzeichnis nicht gefunden: {root}", file=sys.stderr)
        return 2

    checked = 0
    missing: list[tuple[str, str]] = []

    for directory, _, files in os.walk(root):
        for name in files:
            if not name.lower().endswith((".html", ".htm")):
                continue

            path = os.path.join(directory, name)
            with open(path, encoding="utf-8", errors="replace") as handle:
                content = handle.read()

            for raw in LINK_PATTERN.findall(content):
                link = raw.strip()
                if not link or EXTERNAL_PATTERN.match(link):
                    continue

                target = urllib.parse.unquote(link.split("#")[0].split("?")[0])
                if not target or is_allowed(target):
                    continue

                checked += 1
                if not os.path.exists(os.path.normpath(os.path.join(directory, target))):
                    missing.append((os.path.relpath(path, root), target))

    print(f"Geprueft: {checked} lokale Verweise in {root}")

    if missing:
        print(f"Fehlende Ziele: {len(missing)}", file=sys.stderr)
        for source, target in sorted(missing):
            print(f"  {source}: {target}", file=sys.stderr)
        print(
            "Datei ergaenzen, Verweis korrigieren oder - mit Begruendung - "
            "in ALLOWED_MISSING aufnehmen.",
            file=sys.stderr,
        )
        return 1

    print("Alle lokalen Verweise aufloesbar.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else "emtf"))

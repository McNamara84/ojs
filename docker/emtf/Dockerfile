# syntax=docker/dockerfile:1
# =============================================================================
# Statische EMTF-Sammlung (Elektromagnetische Tiefenforschung)
# =============================================================================
# Uebergangsloesung, bis die Inhalte in OJS eingepflegt sind. Ausgeliefert wird
# unter dem Pfad /emtf/, deshalb liegen die Dateien auch im Container unter
# /usr/share/nginx/html/emtf. Traefik entfernt das Praefix bewusst NICHT:
# nginx erzeugt fuer Verzeichnisse ohne Schraegstrich eine Weiterleitung aus dem
# angefragten Pfad. Bei entferntem Praefix zeigte die z. B. auf /2007/ statt
# /emtf/2007/ und liefe ins Leere.
#
# Die Inhalte verlinken untereinander ausschliesslich relativ, deshalb ist ausser
# der Ablage im Unterverzeichnis nichts anzupassen.
# =============================================================================
FROM nginx:1.31.10-alpine-slim@sha256:c293b28729906d97fa5302fce3bd79bd27a3ea9a5d9604e21c6ff0ebf14549fc

COPY docker/emtf/emtf.conf /etc/nginx/conf.d/default.conf
COPY emtf/ /usr/share/nginx/html/emtf/

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["wget", "-q", "--spider", "http://127.0.0.1/emtf/index.html"]

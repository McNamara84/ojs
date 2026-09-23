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
FROM nginx:1.31.6-alpine-slim@sha256:f761b94f2cb9e8e05e2943d5f773609596113ef69b54e2433a996d109a8f78b7

COPY docker/emtf/emtf.conf /etc/nginx/conf.d/default.conf
COPY emtf/ /usr/share/nginx/html/emtf/

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["wget", "-q", "--spider", "http://127.0.0.1/emtf/index.html"]

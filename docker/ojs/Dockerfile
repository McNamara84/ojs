# syntax=docker/dockerfile:1
# =============================================================================
# OJS-Images auf Basis des offiziellen PKP-Images
#   target dev   – lokale Entwicklung (Xdebug), siehe docker-compose.dev.yml
#   target stage – Stage-Deployment (GHCR), siehe docker-compose.stage.yml
# =============================================================================
ARG OJS_IMAGE_TAG=3_5_0-5

FROM pkpofficial/ojs:${OJS_IMAGE_TAG} AS base

USER root

# Das offizielle Image wird nur bei OJS-Releases neu gebaut. Ein in CI taeglich
# wechselnder Wert invalidiert den Cache und holt aktuelle Debian-Sicherheitsupdates.
ARG SYSTEM_PACKAGES_REFRESH=unset
# hadolint ignore=DL3005
RUN echo "System packages refresh: ${SYSTEM_PACKAGES_REFRESH}" && \
    apt-get update && \
    apt-get upgrade -y --no-install-recommends && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-ojs.ini

# pkp-pre-start kann als www-data die Apache-Config nicht per sed anpassen,
# daher ServerName hier setzen (verhindert AH00558-Warnungen).
RUN sed -i -e 's/www.example.com/localhost/g' /etc/apache2/conf-enabled/pkp.conf && \
    echo 'ServerName localhost' > /etc/apache2/conf-enabled/zz-servername.conf

# Zurueck zum User des Basis-Images: pkpofficial/ojs ist bewusst rootless gebaut
# (Label io.containers.rootless=true). Port 80 bindet Apache trotzdem, weil
# die Compose-Dateien net.ipv4.ip_unprivileged_port_start=0 setzen.
USER www-data


# --- Lokale Entwicklung ------------------------------------------------------
FROM base AS dev

USER root
# install-php-extensions ist im offiziellen Image bereits enthalten
RUN install-php-extensions xdebug
COPY xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini
USER www-data


# --- Stage -------------------------------------------------------------------
FROM base AS stage

USER root
COPY stage/reverse-proxy.conf /etc/apache2/conf-enabled/zz-reverse-proxy.conf
COPY stage/php-stage.ini /usr/local/etc/php/conf.d/zz-ojs-stage.ini
COPY --chmod=0755 stage/ojs-stage-start stage/ojs-worker stage/ojs-healthcheck /usr/local/bin/
COPY stage/ojs-configure.php stage/ojs-version-check.php /usr/local/lib/ojs/

# TLS terminiert Traefik. Der 443-VHost braucht Zertifikate, die sonst pkp-pre-start
# erzeugt (auf Stage nicht genutzt) - daher entfernen und mod_ssl abschalten.
#
# config.inc.php liegt im persistenten Volume /var/www/config. Der Symlink
# sorgt dafuer, dass OJS (und der Web-Installer) sie am erwarteten Ort finden.
RUN sed -i '/<VirtualHost \*:443>/,/<\/VirtualHost>/d' /etc/apache2/conf-enabled/pkp.conf && \
    a2dismod -q ssl && \
    apache2ctl -t && \
    mkdir -p /var/www/config && \
    chown www-data:www-data /var/www/config && \
    rm -f /var/www/html/config.inc.php && \
    ln -s /var/www/config/config.inc.php /var/www/html/config.inc.php && \
    chown -h www-data:www-data /var/www/html/config.inc.php
# chown -h ist noetig: /var/www/html ist sticky + world-writable (drwxrwxrwt), und
# fs.protected_symlinks verbietet www-data sonst, einem root-eigenen Symlink zu folgen.

USER www-data
# Eigener Start statt pkp-start: dessen sed -i wuerde den Symlink durch eine Datei
# im Container-Layer ersetzen (Config ginge beim Recreate verloren).
#
# CMD genuegt dafuer: pkp-start haengt im Basis-Image am CMD ("${PKP_CMD}"), nicht am
# ENTRYPOINT. Geerbt wird nur docker-php-entrypoint des offiziellen PHP-Images, das
# lediglich "exec $@" ausfuehrt. Deshalb ENTRYPOINT bewusst unveraendert lassen -
# so ersetzen die Worker ihren Ablauf weiterhin einfach per "command:".
CMD ["ojs-stage-start"]

# syntax=docker/dockerfile:1
# =============================================================================
# OJS-Images auf Basis des offiziellen PKP-Images
#   target dev   – lokale Entwicklung (Xdebug), siehe docker-compose.dev.yml
#   target stage – Stage-Deployment (GHCR), siehe docker-compose.stage.yml
# =============================================================================
ARG OJS_IMAGE_TAG=3_5_0-5

FROM composer:2.11.3@sha256:5b2137f112f939a92788ceb5e55f54859d9dbf467a44037cf7760bdda949f365 AS composer-cli

FROM pkpofficial/ojs:${OJS_IMAGE_TAG} AS pkp

USER www-data
WORKDIR /var/www/html
RUN --mount=type=bind,from=composer-cli,source=/usr/bin/composer,target=/tmp/composer \
    --mount=type=bind,source=security/update-dependencies.sh,target=/tmp/update-dependencies.sh \
    --mount=type=tmpfs,target=/tmp/composer-home \
    if grep -Fq '<release>3.5.0.5</release>' dbscripts/xml/version.xml; then \
        COMPOSER_HOME=/tmp/composer-home sh /tmp/update-dependencies.sh; \
    fi

# OJS bindet TinyMCE auch in js/build.js ein. Fuer diesen Build fehlen im
# Release-Image die npm-Manifeste und das UI-Submodul des PKP-Tags 3_5_0-5.
FROM node:24.22.3-bookworm-slim@sha256:873f586f3794f52281bd039bf02c048a0be08fde619338f9941bad1eb6b34530 AS editor
WORKDIR /build
ADD --checksum=sha256:c6268e64f6eb116ede748126f16ddd96d5100d0f18180196dab524a9a6a5c998 https://raw.githubusercontent.com/pkp/ojs/372e3b84740344db6c2f85193c3eb3e4f539cb00/package.json /upstream/package.json
ADD --checksum=sha256:4956bc253c6f397db349d0d2e7bf2aae585ddb0f27e6300405f05128b60e3bf2 https://raw.githubusercontent.com/pkp/ojs/372e3b84740344db6c2f85193c3eb3e4f539cb00/package-lock.json /upstream/package-lock.json
ADD --checksum=sha256:02fc27258fb308e67ab8976f262612592404f99508144a29feef2e9976e881fb https://codeload.github.com/pkp/ui-library/tar.gz/ac2dabbc72c9f5d04e5da82ff5345f37b66389ea /upstream/ui-library.tar.gz
COPY --from=pkp /var/www/html/ ./
RUN --mount=type=bind,source=security/rebuild-editor.sh,target=/tmp/rebuild-editor.sh \
    mkdir /editor-assets && \
    if grep -Fq '<release>3.5.0.5</release>' dbscripts/xml/version.xml; then \
        sh /tmp/rebuild-editor.sh; \
    fi

FROM pkp AS base
COPY --from=editor --chown=www-data:www-data /editor-assets/ /var/www/html/

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

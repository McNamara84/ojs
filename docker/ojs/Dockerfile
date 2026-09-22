ARG OJS_IMAGE_TAG=3_5_0-5
FROM pkpofficial/ojs:${OJS_IMAGE_TAG}

USER root
# install-php-extensions ist im offiziellen Image bereits enthalten
RUN install-php-extensions xdebug
COPY xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini
# pkp-pre-start kann als www-data die Apache-Config nicht per sed anpassen,
# daher ServerName hier setzen (verhindert AH00558-Warnungen).
RUN sed -i -e 's/www.example.com/localhost/g' /etc/apache2/conf-enabled/pkp.conf && \
    echo 'ServerName localhost' > /etc/apache2/conf-enabled/zz-servername.conf
# Zurueck zum User des Basis-Images: pkpofficial/ojs ist bewusst rootless gebaut
# (Label io.containers.rootless=true). Port 80 bindet Apache trotzdem, weil
# docker-compose.dev.yml net.ipv4.ip_unprivileged_port_start=0 setzt.
USER www-data

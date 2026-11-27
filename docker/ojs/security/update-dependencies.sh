#!/bin/sh
set -eu

# OJS-Kern und PayPal haben getrennte Lockdateien. Nur die explizit
# angegebenen Pakete und Versionen im jeweiligen Lock aktualisieren.
update_packages() {
    php /tmp/composer update --no-dev --no-interaction --no-scripts --prefer-dist \
        guzzlehttp/guzzle:7.17.0 guzzlehttp/promises:2.6.2 guzzlehttp/psr7:2.13.6 \
        symfony/http-foundation:7.4.24 "$@"
}

cd /var/www/html/lib/pkp
php /tmp/composer require --no-update --no-interaction --no-scripts tinymce/tinymce:8.11.0
update_packages laravel/framework:12.74.0 league/commonmark:2.10.10 \
    symfony/http-kernel:7.4.24 symfony/mime:7.4.24 symfony/routing:7.4.24 tinymce/tinymce

cd /var/www/html/plugins/paymethod/paypal
update_packages

#!/bin/sh
set -eu

cp /upstream/package.json /upstream/package-lock.json .
mkdir -p lib/ui-library
tar -xzf /upstream/ui-library.tar.gz --strip-components=1 -C lib/ui-library
npm ci --ignore-scripts --no-audit --no-fund

# Die Vue-Oberflaeche und das PHP-Plugin verwenden denselben Editor.
rm -r node_modules/tinymce
cp -R lib/pkp/lib/vendor/tinymce/tinymce node_modules/tinymce
npm run build:backend

cp --parents js/build.js styles/build.css /editor-assets/
cp -R --parents lib/pkp/styles/tinymce /editor-assets/

<?php

/**
 * Gibt "<Code-Version> <DB-Version> <Richtung>" aus, z. B. "3.5.0.5 3.5.0.4 db-older".
 *
 * Richtung (aus Sicht der Datenbank, ermittelt von PKP\site\Version::compare):
 *   equal    - Datenbank und Code sind auf demselben Stand
 *   db-older - Datenbank ist aelter: Upgrade noetig
 *   db-newer - Datenbank ist neuer als der Code (z. B. nach einem Image-Rollback)
 *
 * Bewusst NICHT "tools/upgrade.php check": Dessen check() ruft zuerst
 * VersionCheck::getLatestVersion() auf und laedt die Versionsdatei von pkp.sfu.ca.
 * Ohne ausgehende Verbindung endet das in einem Fatal Error, bevor ueberhaupt eine
 * Version ausgegeben wird - der Container wuerde in einer Neustartschleife landen.
 * Dieser Vergleich ist rein lokal (Code-Version aus der Datei, DB-Version aus der DB).
 */

declare(strict_types=1);

chdir('/var/www/html');
require '/var/www/html/tools/bootstrap.php';

$code = \PKP\site\VersionCheck::getCurrentCodeVersion();
$database = \PKP\site\VersionCheck::getCurrentDBVersion();

if (!$code || !$database) {
    fwrite(STDERR, "[ojs-version-check] Code- oder Datenbankversion nicht ermittelbar.\n");
    exit(1);
}

$comparison = $database->compare($code);
$direction = $comparison < 0 ? 'db-older' : ($comparison > 0 ? 'db-newer' : 'equal');

printf("%s %s %s\n", $code->getVersionString(false), $database->getVersionString(false), $direction);

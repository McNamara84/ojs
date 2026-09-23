<?php

/**
 * Gibt "<Code-Version> <DB-Version>" aus, z. B. "3.5.0.5 3.5.0.4".
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

printf("%s %s\n", $code->getVersionString(false), $database->getVersionString(false));

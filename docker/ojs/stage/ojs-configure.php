<?php

/**
 * Setzt die umgebungsabhaengigen Werte der OJS-Konfiguration (Stage).
 *
 * Laeuft bei jedem Start von ojs-app und ist idempotent:
 *  - fehlt config.inc.php im Volume, wird sie aus config.TEMPLATE.inc.php erzeugt;
 *  - DB, Mail, URL usw. werden aus Umgebungsvariablen gesetzt.
 *
 * Sonderfall "installed" und "app_key": Normalerweise gehoeren beide dem
 * Web-Installer und werden nicht angefasst. Enthaelt die Datenbank jedoch bereits
 * eine Installation, waehrend die Konfiguration "installed = Off" meldet (typisch
 * nach einem Verlust der config.inc.php), stellt dieses Skript beides wieder her:
 * es setzt "installed = On" und erzeugt bei Bedarf einen neuen "app_key". Sonst
 * wuerde OJS den Installer zeigen, und wer ihn abschickt, ueberschreibt den
 * Datenbestand. Siehe Abschnitt "Bestehende Installation erkennen" weiter unten.
 *
 * Aufruf: php ojs-configure.php [--config=/pfad/config.inc.php]
 */

declare(strict_types=1);

const TEMPLATE = '/var/www/html/config.TEMPLATE.inc.php';

$options = getopt('', ['config::']);
$configFile = $options['config'] ?? '/var/www/config/config.inc.php';

function fail(string $message): never
{
    fwrite(STDERR, "[ojs-configure] FEHLER: {$message}\n");
    exit(1);
}

function info(string $message): void
{
    fwrite(STDOUT, "[ojs-configure] {$message}\n");
}

function env(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default === null) {
            fail("Pflichtvariable {$name} ist nicht gesetzt.");
        }
        return $default;
    }
    if (preg_match('/[\r\n]/', $value)) {
        fail("{$name} darf keine Zeilenumbrueche enthalten.");
    }
    return $value;
}

/** String-Wert: OJS liest "..." per stripslashes, daher addslashes. */
function quoted(string $value): string
{
    return '"' . addslashes($value) . '"';
}

/**
 * Prueft, ob die Datenbank bereits eine OJS-Installation enthaelt.
 *
 * Entscheidend ist eine aktuelle Version des Produkts in der Tabelle "versions";
 * die legt erst der Installer bzw. das Upgrade an. Ist die Datenbank leer oder nicht
 * erreichbar, liefert die Funktion false und der Web-Installer bleibt zustaendig.
 */
function databaseHasInstallation(array $db): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = @new mysqli($db['host'], $db['user'], $db['password'], $db['name']);

    if ($connection->connect_errno) {
        info("Datenbank nicht erreichbar ({$connection->connect_error}) - Installationsstatus unveraendert.");
        return false;
    }

    $result = @$connection->query("SELECT COUNT(*) FROM versions WHERE current = 1 AND product = 'ojs2'");
    $count = $result ? (int) $result->fetch_row()[0] : 0;
    $connection->close();

    return $count > 0;
}

/**
 * Setzt key = value in einer INI-Sektion. Bevorzugt eine aktive Zeile, sonst wird
 * eine auskommentierte Zeile ersetzt, sonst direkt nach dem Sektionskopf eingefuegt.
 */
function setIniValue(array &$lines, string $section, string $key, string $value): void
{
    $start = null;
    foreach ($lines as $i => $line) {
        if (trim($line) === "[{$section}]") {
            $start = $i;
            break;
        }
    }
    if ($start === null) {
        fail("Sektion [{$section}] nicht in config.inc.php gefunden.");
    }

    $end = count($lines);
    for ($i = $start + 1; $i < count($lines); $i++) {
        if (preg_match('/^\s*\[/', $lines[$i])) {
            $end = $i;
            break;
        }
    }

    $pattern = preg_quote($key, '/');
    $active = $commented = null;
    for ($i = $start + 1; $i < $end; $i++) {
        if ($active === null && preg_match("/^\s*{$pattern}\s*=/", $lines[$i])) {
            $active = $i;
        }
        if ($commented === null && preg_match("/^\s*;\s*{$pattern}\s*=/", $lines[$i])) {
            $commented = $i;
        }
    }

    $newLine = rtrim("{$key} = {$value}");
    if ($active !== null) {
        $lines[$active] = $newLine;
    } elseif ($commented !== null) {
        $lines[$commented] = $newLine;
    } else {
        array_splice($lines, $start + 1, 0, [$newLine]);
    }
}

// --- Werte aus der Umgebung ----------------------------------------------------
$baseUrl = rtrim(env('OJS_BASE_URL'), '/');
$host = parse_url($baseUrl, PHP_URL_HOST);
if (!is_string($host) || $host === '') {
    fail("OJS_BASE_URL ist keine gueltige URL: {$baseUrl}");
}

$mailEncryption = strtolower(env('MAIL_ENCRYPTION', 'tls'));
if (!in_array($mailEncryption, ['tls', 'ssl', 'none'], true)) {
    fail("MAIL_ENCRYPTION muss tls, ssl oder none sein (ist: {$mailEncryption}).");
}
$mailFrom = env('MAIL_FROM_ADDRESS');
if (filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false) {
    fail("MAIL_FROM_ADDRESS ist keine gueltige E-Mail-Adresse: {$mailFrom}");
}

$dbConfig = [
    'host' => env('OJS_DB_HOST', 'ojs-db'),
    'user' => env('OJS_DB_USER', 'ojs'),
    'password' => env('OJS_DB_PASSWORD'),
    'name' => env('OJS_DB_NAME', 'ojs'),
];

$settings = [
    ['general', 'base_url', quoted($baseUrl)],
    // Gleiches Format wie der Web-Installer, sonst wechselt die Zeile bei jedem Start
    ['general', 'allowed_hosts', quoted(json_encode([$host]))],
    ['general', 'trust_x_forwarded_for', 'On'],
    ['general', 'restful_urls', 'On'],
    ['general', 'time_zone', quoted(env('TZ', 'Europe/Berlin'))],

    ['database', 'driver', 'mysqli'],
    ['database', 'host', quoted($dbConfig['host'])],
    ['database', 'username', quoted($dbConfig['user'])],
    ['database', 'password', quoted($dbConfig['password'])],
    ['database', 'name', quoted($dbConfig['name'])],

    ['files', 'files_dir', quoted('/var/www/files')],

    ['email', 'default', 'smtp'],
    ['email', 'smtp', 'On'],
    ['email', 'smtp_server', quoted(env('MAIL_HOST'))],
    ['email', 'smtp_port', (string) (int) env('MAIL_PORT', '587')],
    ['email', 'allow_envelope_sender', 'On'],
    ['email', 'default_envelope_sender', quoted($mailFrom)],
    ['email', 'force_default_envelope_sender', 'On'],
    ['email', 'force_dmarc_compliant_from', 'On'],
    ['email', 'dmarc_compliant_from_displayname', quoted('%n via %s')],

    // Uebernehmen die Container ojs-queue und ojs-scheduler
    ['queues', 'job_runner', 'Off'],
    ['schedule', 'task_runner', 'Off'],

    ['debug', 'display_errors', 'Off'],
    ['debug', 'show_stacktrace', 'Off'],
];

// Diese Schluessel werden immer geschrieben, auch leer: Ein leerer Wert liest sich in
// OJS als null (Laravel setzt dann weder Verschluesselung noch Zugangsdaten). Wuerden
// sie nur bedingt gesetzt, bliebe nach dem Entfernen einer Variablen der alte Wert in
// der persistenten config.inc.php aktiv.
$mailUser = env('MAIL_USERNAME', '');
$settings[] = ['email', 'smtp_auth', $mailEncryption === 'none' ? '' : $mailEncryption];
$settings[] = ['email', 'smtp_username', $mailUser === '' ? '' : quoted($mailUser)];
$settings[] = ['email', 'smtp_password', $mailUser === '' ? '' : quoted(env('MAIL_PASSWORD'))];

// --- Config-Datei erzeugen bzw. laden -----------------------------------------
if (!is_file($configFile)) {
    if (!copy(TEMPLATE, $configFile)) {
        fail("Konnte {$configFile} nicht aus dem Template erzeugen.");
    }
    info("{$configFile} aus dem Template erzeugt.");
}

$content = file_get_contents($configFile);
if ($content === false) {
    fail("Konnte {$configFile} nicht lesen.");
}
$lines = preg_split('/\r?\n/', rtrim($content, "\r\n"));

foreach ($settings as [$section, $key, $value]) {
    setIniValue($lines, $section, $key, $value);
}

$newContent = implode("\n", $lines) . "\n";
if ($newContent !== $content) {
    // In-place schreiben (kein rename), damit Datei, Inode und Rechte erhalten bleiben
    if (file_put_contents($configFile, $newContent, LOCK_EX) === false) {
        fail("Konnte {$configFile} nicht schreiben.");
    }
    info('Konfiguration aktualisiert.');
} else {
    info('Konfiguration unveraendert.');
}

$installed = preg_match('/^\s*installed\s*=\s*On\b/mi', $newContent) === 1;

// --- Bestehende Installation erkennen ------------------------------------------
// Geht die config.inc.php verloren (z. B. weil sie nur im Container lag), steht
// installed = Off, obwohl die Datenbank vollstaendig ist. OJS zeigt dann den
// Installer - und wer ihn abschickt, ueberschreibt den Bestand. Deshalb pruefen wir
// die Datenbank und markieren die Installation wieder als vorhanden.
if (!$installed && databaseHasInstallation($dbConfig)) {
    info('Datenbank enthaelt bereits eine OJS-Installation - setze installed = On.');
    setIniValue($lines, 'general', 'installed', 'On');
    $newContent = implode("\n", $lines) . "\n";
    if (file_put_contents($configFile, $newContent, LOCK_EX) === false) {
        fail("Konnte {$configFile} nicht schreiben.");
    }
    $installed = true;
}

// Ohne app_key startet OJS 3.5 nicht. Bei verlorener Config fehlt er.
if ($installed && !preg_match('/^\s*app_key\s*=\s*"?base64:/mi', $newContent)) {
    info('Kein app_key vorhanden - erzeuge einen neuen (Nutzer muessen sich neu anmelden).');
    exec('php /var/www/html/lib/pkp/tools/appKey.php generate --force 2>&1', $output, $status);
    if ($status !== 0) {
        fail('app_key konnte nicht erzeugt werden: ' . implode(' ', $output));
    }
}

info('Status: ' . ($installed ? 'installiert' : "NICHT installiert – Web-Installer unter {$baseUrl} aufrufen"));

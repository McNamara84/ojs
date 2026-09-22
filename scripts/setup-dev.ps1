<#
.SYNOPSIS
    Einmaliges (idempotentes) Setup der lokalen OJS-Entwicklungsumgebung.

.DESCRIPTION
    1. Prueft, ob Docker laeuft und die Host-Ports frei sind.
    2. Erzeugt .env aus .env.example mit zufaelligen Passwoertern (falls nicht vorhanden).
    3. Baut das OJS-Image (offizielles PKP-Image + Xdebug).
    4. Erzeugt docker/ojs/config.inc.php aus dem Template des Images (falls nicht vorhanden).
    5. Optional (-SyncSource): kopiert den OJS-Code nach ./ojs-src (fuer Xdebug-Path-Mapping).

.PARAMETER SyncSource
    Nur den OJS-Code aus dem laufenden Container nach ./ojs-src kopieren.

.PARAMETER Start
    Nach dem Setup den Stack starten.

.EXAMPLE
    .\scripts\setup-dev.ps1 -Start
    .\scripts\setup-dev.ps1 -SyncSource
#>
[CmdletBinding()]
param(
    [switch]$SyncSource,
    [switch]$Start
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$ComposeFile = Join-Path $Root 'docker-compose.dev.yml'
$EnvFile = Join-Path $Root '.env'
$EnvExample = Join-Path $Root '.env.example'
$ConfigFile = Join-Path $Root 'docker\ojs\config.inc.php'
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }

function Invoke-Compose {
    & docker compose -f $ComposeFile --env-file $EnvFile @args
    if ($LASTEXITCODE -ne 0) { throw "docker compose $args fehlgeschlagen (Exit $LASTEXITCODE)." }
}

function New-RandomSecret([int]$Length = 24) {
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'.ToCharArray()
    $bytes = New-Object byte[] $Length
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}

function Read-EnvFile([string]$Path) {
    $values = @{}
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$') {
            $values[$Matches[1]] = $Matches[2].Trim('"')
        }
    }
    $values
}

# Setzt key = value in einer INI-Sektion. Bevorzugt eine aktive Zeile,
# sonst wird eine auskommentierte Zeile ersetzt, sonst nach dem Header eingefuegt.
function Set-IniValue([System.Collections.Generic.List[string]]$Lines, [string]$Section, [string]$Key, [string]$Value) {
    $start = $Lines.FindIndex([Predicate[string]] { param($l) $l.Trim() -eq "[$Section]" })
    if ($start -lt 0) { throw "Sektion [$Section] nicht in config.inc.php gefunden." }
    $end = $Lines.Count
    for ($i = $start + 1; $i -lt $Lines.Count; $i++) {
        if ($Lines[$i] -match '^\s*\[') { $end = $i; break }
    }
    $keyPattern = [regex]::Escape($Key)
    $active = -1; $commented = -1
    for ($i = $start + 1; $i -lt $end; $i++) {
        if ($active -lt 0 -and $Lines[$i] -match "^\s*$keyPattern\s*=") { $active = $i }
        if ($commented -lt 0 -and $Lines[$i] -match "^\s*;\s*$keyPattern\s*=") { $commented = $i }
    }
    $newLine = "$Key = $Value"
    if ($active -ge 0) { $Lines[$active] = $newLine }
    elseif ($commented -ge 0) { $Lines[$commented] = $newLine }
    else { $Lines.Insert($start + 1, $newLine) }
}

function Test-ProjectRunning {
    $ids = & docker compose -f $ComposeFile --env-file $EnvFile ps -q 2>$null
    return [bool]$ids
}

# --- 1. Docker pruefen ---------------------------------------------------------
Write-Step 'Pruefe Docker ...'
& docker info --format '{{.ServerVersion}}' *> $null
if ($LASTEXITCODE -ne 0) { throw 'Docker laeuft nicht. Bitte Docker Desktop starten.' }

# --- 2. .env erzeugen ----------------------------------------------------------
if (-not (Test-Path $EnvFile)) {
    Write-Step '.env aus .env.example erzeugen (zufaellige Passwoerter) ...'
    $content = [System.IO.File]::ReadAllText($EnvExample)
    $content = $content -replace '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$(New-RandomSecret)"
    $content = $content -replace '(?m)^DB_ROOT_PASSWORD=.*$', "DB_ROOT_PASSWORD=$(New-RandomSecret)"
    [System.IO.File]::WriteAllText($EnvFile, $content, $Utf8NoBom)
} else {
    Write-Step '.env existiert bereits - bleibt unveraendert.'
}
$envValues = Read-EnvFile $EnvFile

# --- Nur Code-Sync -------------------------------------------------------------
if ($SyncSource) {
    $target = Join-Path $Root 'ojs-src'
    Write-Step "Kopiere OJS-Code aus dem Container nach $target ..."
    if (-not (Test-ProjectRunning)) { throw 'Stack laeuft nicht. Erst starten: docker compose -f docker-compose.dev.yml up -d' }
    if (Test-Path $target) { Remove-Item -Recurse -Force $target }
    Invoke-Compose cp 'ojs-app:/var/www/html' $target
    Write-Step 'Fertig. ojs-src ist eine reine Referenzkopie (Aenderungen dort wirken NICHT im Container).'
    return
}

# --- 3. Ports pruefen ----------------------------------------------------------
if (-not (Test-ProjectRunning)) {
    Write-Step 'Pruefe freie Host-Ports ...'
    foreach ($name in 'OJS_HTTP_PORT', 'MAILPIT_HTTP_PORT') {
        $port = [int]$envValues[$name]
        if (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue) {
            throw "Port $port ($name) ist bereits belegt. Bitte in .env aendern."
        }
    }
}

# --- 4. Image bauen ------------------------------------------------------------
Write-Step 'Baue OJS-Image (pkpofficial/ojs + Xdebug) ...'
Invoke-Compose pull ojs-db ojs-mailpit
Invoke-Compose build --pull ojs-app

# --- 5. config.inc.php erzeugen ------------------------------------------------
if (-not (Test-Path $ConfigFile)) {
    Write-Step 'Erzeuge docker/ojs/config.inc.php aus dem Image-Template ...'
    $image = "ojs-dev:$($envValues['OJS_IMAGE_TAG'])"
    $cid = (& docker create $image).Trim()
    try {
        & docker cp "${cid}:/var/www/html/config.TEMPLATE.inc.php" $ConfigFile
        if ($LASTEXITCODE -ne 0) { throw 'Template konnte nicht aus dem Image kopiert werden.' }
    } finally {
        & docker rm $cid *> $null
    }

    $lines = New-Object System.Collections.Generic.List[string]
    $lines.AddRange([System.IO.File]::ReadAllLines($ConfigFile))

    Set-IniValue $lines 'general' 'base_url' "`"$($envValues['OJS_BASE_URL'])`""
    Set-IniValue $lines 'general' 'restful_urls' 'On'
    Set-IniValue $lines 'general' 'trust_x_forwarded_for' 'Off'
    Set-IniValue $lines 'general' 'time_zone' "`"$($envValues['TZ'])`""

    Set-IniValue $lines 'database' 'driver' 'mysqli'
    Set-IniValue $lines 'database' 'host' 'ojs-db'
    Set-IniValue $lines 'database' 'username' $envValues['DB_USER']
    Set-IniValue $lines 'database' 'password' $envValues['DB_PASSWORD']
    Set-IniValue $lines 'database' 'name' $envValues['DB_NAME']

    Set-IniValue $lines 'files' 'files_dir' '/var/www/files'

    Set-IniValue $lines 'email' 'default' 'smtp'
    Set-IniValue $lines 'email' 'smtp' 'On'
    Set-IniValue $lines 'email' 'smtp_server' 'ojs-mailpit'
    Set-IniValue $lines 'email' 'smtp_port' '1025'

    Set-IniValue $lines 'debug' 'show_stacktrace' 'On'
    Set-IniValue $lines 'debug' 'display_errors' 'On'

    [System.IO.File]::WriteAllText($ConfigFile, (($lines -join "`n") + "`n"), $Utf8NoBom)
} else {
    Write-Step 'docker/ojs/config.inc.php existiert bereits - bleibt unveraendert.'
}

# --- 6. Optional starten -------------------------------------------------------
if ($Start) {
    Write-Step 'Starte Stack ...'
    Invoke-Compose up -d --wait
}

Write-Host ''
Write-Host "OJS:     http://localhost:$($envValues['OJS_HTTP_PORT'])" -ForegroundColor Green
Write-Host "Mailpit: http://localhost:$($envValues['MAILPIT_HTTP_PORT'])" -ForegroundColor Green
Write-Host ''
Write-Host 'Web-Installer: Datenbank-Host "ojs-db", Zugangsdaten stehen in .env (DB_USER / DB_PASSWORD / DB_NAME).'
Write-Host 'Nach der Installation fuer Xdebug: .\scripts\setup-dev.ps1 -SyncSource'

# OJS – lokale Entwicklungsumgebung

Lokale Docker-Umgebung für [Open Journal Systems](https://github.com/pkp/ojs) auf Basis des offiziellen PKP-Images.

| Dienst | Version | Adresse |
|---|---|---|
| OJS | 3.5 LTS (`pkpofficial/ojs:3_5_0-5`, PHP 8.3, Apache) + Xdebug | http://localhost:8081 |
| Mailpit | `axllent/mailpit:v1.31` | http://localhost:8025 |
| MariaDB | 11.8 LTS | nur intern (`ojs-db:3306`) |

Die Umgebung ist eigenständig: Sie hat ein eigenes Netzwerk und keinen Reverse-Proxy. Alle Ports sind nur an `127.0.0.1` gebunden.

> **Stage:** Jeder Merge nach `main` wird automatisch nach **https://ojs.rz-vm182.gfz.de** (nur im VPN) deployt. Siehe [docs/stage-deployment.md](docs/stage-deployment.md).
>
> **Produktion:** Ein veröffentlichter Release (`vX.Y.Z`) befördert genau den auf Stage erprobten Image-Digest nach **https://ojs.rz-vm499.gfz.de**. Siehe [docs/production-deployment.md](docs/production-deployment.md).

## Erstes Setup

Voraussetzung: Docker Desktop (WSL2) läuft.

```powershell
.\scripts\setup-dev.ps1 -Start
```

Das Skript ist idempotent und erledigt Folgendes:

1. Es erzeugt `.env` mit zufälligen DB-Passwörtern.
2. Es baut das Image.
3. Es erzeugt `docker/ojs/config.inc.php` aus dem Template des Images (DB, Mailpit-SMTP, `base_url`, Debug-Ausgabe).
4. Es startet den Stack.

Bereits vorhandene `.env`- oder `config.inc.php`-Dateien werden nicht überschrieben.

Falls das Skript wegen der Execution Policy blockiert wird:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\setup-dev.ps1 -Start
```

### Web-Installer (einmalig)

Rufe http://localhost:8081 auf und fülle den Installer aus:

- **Administrator:** Benutzername, Passwort und E-Mail frei wählen.
- **Sprache:** Deutsch und/oder Englisch.
- **Dateiverzeichnis:** `/var/www/files` (vorbelegt).
- **Datenbank:**
  - Treiber `MySQLi`, Host `ojs-db`.
  - Benutzer, Passwort und Name aus `.env` (`DB_USER`, `DB_PASSWORD`, `DB_NAME`).
  - „Neue Datenbank anlegen“ **nicht** anhaken.
- **OAI-Repository-ID:** `localhost`. Beacon nach Wunsch deaktivieren.

Der Installer schreibt `installed = On` und den `app_key` nach `docker/ojs/config.inc.php`.

## Alltag

```powershell
docker compose -f docker-compose.dev.yml up -d
```

```powershell
docker compose -f docker-compose.dev.yml logs -f ojs-app
```

```powershell
docker compose -f docker-compose.dev.yml down
```

- **PHP-Fehler:** Sie werden nicht im HTML angezeigt (`[debug] display_errors = Off`), sondern vollständig mit `logs -f ojs-app` ausgegeben. OJS 3.5.0-5 erzeugt unter PHP 8.3 einige `Deprecated`-Meldungen aus dem eigenen Kerncode (`lib/pkp/…`). Würden sie in der Seite erscheinen, bräche das Header, Doctype und den TinyMCE-Editor.
- **Mails:** Alle E-Mails von OJS landen in Mailpit (http://localhost:8025). Nichts verlässt den Rechner.
- **Jobs und geplante Aufgaben:** In `config.inc.php` sind `[queues] job_runner = On` und `[schedule] task_runner = On` aktiv (OJS-Standard). Beide Runner laufen am Ende von Web-Requests, der Task-Runner höchstens alle 60 Sekunden. Geplante Aufgaben laufen also nur, solange jemand OJS aufruft. Für die lokale Entwicklung reicht das.
  - Einen Lauf erzwingen:

    ```powershell
    docker compose -f docker-compose.dev.yml exec ojs-app php lib/pkp/tools/scheduler.php run
    ```

  - Jobs aus der Warteschlange abarbeiten:

    ```powershell
    docker compose -f docker-compose.dev.yml exec ojs-app php lib/pkp/tools/jobs.php work --stop-when-empty
    ```
- **Shell im Container:**

  ```powershell
  docker compose -f docker-compose.dev.yml exec ojs-app bash
  ```

## Debugging mit Xdebug

1. Die Host-Kopie des Codes für das Path-Mapping erzeugen, einmalig und nach jedem OJS-Update:

   ```powershell
   .\scripts\setup-dev.ps1 -SyncSource
   ```

   `ojs-src/` ist eine **reine Referenzkopie**. Änderungen dort wirken nicht im Container.
2. In `.env` den Wert `XDEBUG_MODE=debug` setzen und den Container neu erstellen:

   ```powershell
   docker compose -f docker-compose.dev.yml up -d ojs-app
   ```

3. In VS Code „Xdebug: OJS (Docker)“ starten (siehe `.vscode/launch.json`, benötigt die Erweiterung *PHP Debug*).
   - **PhpStorm:** Unter Settings → PHP → Servers einen Server `localhost`, Port `8081` anlegen, Path-Mapping `ojs-src` → `/var/www/html` eintragen und auf Port 9003 lauschen.
   - **Firewall:** Beim ersten Debuggen fragt die Windows-Firewall eventuell nach, ob die IDE eingehende Verbindungen annehmen darf. Das muss erlaubt werden.
4. Nach dem Debuggen `XDEBUG_MODE=off` setzen, denn Xdebug bremst spürbar.

### Eigene Plugins entwickeln

In `docker-compose.dev.yml` die auskommentierte Plugin-Zeile unter `ojs-app.volumes` aktivieren und anpassen, z. B.:

```yaml
- ./plugins/generic/myPlugin:/var/www/html/plugins/generic/myPlugin
```

Danach `docker compose -f docker-compose.dev.yml up -d ojs-app` ausführen.

## Wartung

- **OJS-Update:**
  1. `OJS_IMAGE_TAG` in `.env` anpassen (verfügbare Tags: https://hub.docker.com/r/pkpofficial/ojs/tags). Für den PR auch den Standardwert in `docker/ojs/Dockerfile` und `.env.example` anheben. Stage baut mit dem Wert aus dem Dockerfile (siehe [docs/stage-deployment.md](docs/stage-deployment.md#ojs-version-aktualisieren)).
  2. Neu bauen und starten:

     ```powershell
     docker compose -f docker-compose.dev.yml up -d --build
     ```

  3. Die Datenbank migrieren:

     ```powershell
     docker compose -f docker-compose.dev.yml exec ojs-app php tools/upgrade.php upgrade
     ```

  4. Danach `.\scripts\setup-dev.ps1 -SyncSource` ausführen.
- **Mailpit- und MariaDB-Patches:**

  ```powershell
  docker compose -f docker-compose.dev.yml pull ojs-mailpit ojs-db
  ```

  Danach `up -d` ausführen.
- **DB-Backup:**

  Der Dump wird im Container in eine Datei geschrieben und dann herauskopiert. So stören weder ein Pseudo-TTY noch die Umkodierung bei der PowerShell-Umleitung (`>`).

  ```powershell
  docker compose -f docker-compose.dev.yml exec -T ojs-db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --result-file=/tmp/backup.sql "$MARIADB_DATABASE"'
  ```

  ```powershell
  docker compose -f docker-compose.dev.yml cp ojs-db:/tmp/backup.sql ./backup.sql
  ```

- **Kompletter Reset** (löscht DB, Uploads und Konfiguration):

  ```powershell
  docker compose -f docker-compose.dev.yml down -v
  ```

  Danach `docker/ojs/config.inc.php` löschen und `.\scripts\setup-dev.ps1 -Start` ausführen.

## Ports ändern

`OJS_HTTP_PORT` bzw. `MAILPIT_HTTP_PORT` in `.env` ändern. Bei einem neuen OJS-Port zusätzlich `OJS_BASE_URL` in `.env` **und** `base_url` in `docker/ojs/config.inc.php` anpassen.

## Bekannte, harmlose Log-Meldungen

Beim Start von `ojs-app` erscheinen diese Meldungen:

```
sed: couldn't open temporary file /etc/apache2/...: Permission denied
sed: cannot rename /var/www/html/sed...: Device or resource busy
```

Das Startskript des offiziellen Images (`pkp-pre-start`) versucht, Konfigurationsdateien per `sed -i` anzupassen. Das Image läuft aber ohne Root-Rechte, und `config.inc.php` ist als Einzeldatei gemountet, deshalb schlägt das fehl. Die betroffenen Werte (`ServerName`, `restful_urls`) sind bereits im Image bzw. in der Config gesetzt. Die Warnungen des SSL-VHosts auf Port 443 sind ebenfalls irrelevant, weil Port 443 nicht veröffentlicht wird.

## Dateien

| Datei | Zweck |
|---|---|
| `docker-compose.dev.yml` | lokaler Stack |
| `docker-compose.stage.yml` | Stage-Stack (Vorlage, der Digest wird von CI in `deploy/stage` gepinnt) |
| `docker-compose.prod.yml` | Produktions-Stack (Vorlage, Digest in `deploy/prod`, nutzt die Bestandsvolumes) |
| `.env.example` / `.env` | Konfiguration (`.env` ist gitignored) |
| `docker/ojs/Dockerfile` | offizielles Image + Debian-Updates, Targets `dev` (Xdebug) und `stage` |
| `docker/ojs/php.ini` | PHP-Limits für dev und stage (Uploads bis 64 MB) |
| `docker/ojs/xdebug.ini` | Xdebug-Einstellungen (Modus über `XDEBUG_MODE`) |
| `docker/ojs/stage/` | Stage-Entrypoints, Config-Generator, Healthcheck, Proxy-Konfiguration |
| `docker/emtf/` + `emtf/` | statische EMTF-Sammlung, ausgeliefert unter `/emtf/` (Übergangslösung bis zur Übernahme in OJS) |
| `.github/workflows/` | `stage-checks.yml` (Gate), `publish-stage-images.yml` (Stage), `production-release-signal.yml` und `promote-production-release.yml` (Produktion) |
| `.trivyignore` | bewusst akzeptierte Sicherheitsbefunde mit Ablaufdatum |
| `docker/ojs/config.inc.php` | OJS-Konfiguration (generiert, gitignored, enthält Secrets) |
| `scripts/setup-dev.ps1` | Setup, Code-Sync |
| `.vscode/launch.json` | Xdebug-Konfiguration für VS Code |

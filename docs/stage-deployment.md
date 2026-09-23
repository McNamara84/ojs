# Stage-Deployment (RZ-VM182)

OJS läuft auf Stage unter **https://ojs.rz-vm182.gfz.de** und ist nur im GFZ-VPN erreichbar. Das Verfahren entspricht dem von ERNIE ([`docs/stage-container-deployment.md`](https://github.com/McNamara84/ernie/blob/main/docs/stage-container-deployment.md)): RZ-VM182 baut keine Images. GitHub Actions baut das Image, pusht es nach GHCR und bewegt den maschinell gepflegten Branch `deploy/stage`. Dessen `docker-compose.stage.yml` pinnt das Image per unveränderlichem Digest. Portainer pollt diesen Branch.

## Ablauf

1. Feature-Branch → Pull Request → Merge nach `main`.
2. **Stage Checks** (`.github/workflows/stage-checks.yml`) prüft den Commit:
   - hadolint, shellcheck, actionlint;
   - Validierung beider Compose-Dateien;
   - die Anzahl der Image-Marker;
   - Build des Targets `stage`;
   - PHP-Syntax der Hilfsskripte;
   - **Smoke-Test** des echten Stage-Stacks: Installer-Redirect, HTTPS-Links, Worker-Wartezustand;
   - **Trivy** (HIGH/CRITICAL, `--ignore-unfixed`, mit `.trivyignore`).
3. **Publish Stage Images** (`.github/workflows/publish-stage-images.yml`) startet nach einem erfolgreichen Push-Lauf auf `main` (`workflow_run`):
   1. Er baut `ghcr.io/mcnamara84/ojs-app:sha-<commit>` und pusht das Image.
   2. Er scannt den **exakten Digest** noch einmal mit Trivy.
   3. Er ersetzt die drei Marker `ojs-app:deployment-template` durch `@sha256:<digest>`.
   4. Er schiebt `deploy/stage` per Compare-and-Swap weiter.
4. Portainer erkennt den neuen Commit auf `deploy/stage`, zieht das Image und erstellt die Container neu.

Niemand arbeitet auf `deploy/stage` oder mergt dorthin. Jeder Deployment-Commit hat den geprüften Quell-Commit als Elternteil. Ein älterer Workflow-Lauf kann einen neueren Stand nicht überschreiben.

## Architektur

| Container | Aufgabe |
|---|---|
| `ojs-stage-app` | Apache/PHP 8.3 (OJS). Hängt am Netzwerk `traefik` mit dem Router `ojs-router` |
| `ojs-stage-queue` | Hintergrund-Jobs (`jobs.php work`, Neustart stündlich) |
| `ojs-stage-scheduler` | geplante Aufgaben (`scheduler.php work`) |
| `ojs-stage-db` | MariaDB 11.8, per Digest gepinnt |

| Volume | Inhalt |
|---|---|
| `ojs-config` | `config.inc.php` inkl. `app_key` und `installed = On`. **Nicht löschen**, sonst ist eine Neuinstallation nötig |
| `ojs-files` | private Uploads (Manuskripte) |
| `ojs-public` | öffentliche Dateien (Logos, Journal-Bilder) |
| `ojs-db-data` | Datenbank |

Beim Start setzt `ojs-app` die umgebungsabhängigen Werte der `config.inc.php` aus den Stack-Variablen (`docker/ojs/stage/ojs-configure.php`):

- **URL und Zugriff:** Basis-URL, `allowed_hosts` und die Proxy-Einstellungen.
- **Zugangsdaten:** Datenbank und SMTP.
- **Mail-Absender:** DMARC-konform über `MAIL_FROM_ADDRESS`.
- **Runner:** Die eingebauten Job- und Task-Runner sind aus, weil die Worker-Container diese Arbeit übernehmen.

`installed` und `app_key` bleiben unberührt, solange sie stimmen. Nur wenn die Datenbank bereits eine Installation enthält, die Konfiguration aber `installed = Off` meldet, stellt das Skript beides wieder her (siehe [Selbstheilung nach Config-Verlust](production-deployment.md#selbstheilung-nach-config-verlust)). Alle übrigen Werte werden bei jedem Start neu geschrieben, auch leer. Entfernst du also `MAIL_USERNAME` aus dem Stack, verschwinden Benutzername und Passwort auch aus der Config.

`ojs-app` vergleicht beim Start die Code- mit der Datenbankversion und handelt je nach Richtung:

| Fall | Verhalten |
|---|---|
| Versionen gleich | normaler Start |
| Datenbank älter | `tools/upgrade.php upgrade` läuft automatisch, danach startet Apache |
| Datenbank neuer (typisch nach einem Image-Rollback) | Der Container startet **nicht** und nennt im Log die Auswege. Ein Upgrade mit älterem Code wäre der falsche Migrationspfad, und ein Downgrade beherrscht OJS nicht |

Mit `OJS_RUN_UPGRADE=0` wird der Vergleich übersprungen und der Container startet ohne Prüfung. Den Versionsvergleich macht `ojs-version-check.php` rein lokal. `tools/upgrade.php check` ist dafür ungeeignet, weil es zuerst die Versionsdatei von `pkp.sfu.ca` lädt und ohne ausgehende Verbindung abbricht, bevor es eine Version ausgibt.

Queue und Scheduler warten, bis OJS installiert ist **und** Code- und Datenbankversion übereinstimmen. Während eines Upgrades pausieren sie deshalb und starten danach von selbst (Prüfung alle 30 Sekunden, einstellbar über `OJS_WORKER_WAIT_SECONDS`).

## Einmalige Einrichtung

### 1. Repository öffentlich machen

Gehe in GitHub zu **Settings → General → Danger Zone → Change visibility → Public**. Die Git-Historie enthält keine Geheimnisse: `.env` und `config.inc.php` wurden nie committet.

### 2. Workflow-Rechte prüfen

Unter **Settings → Actions → General → Workflow permissions** müssen die Schreibrechte, die ein Workflow anfordert, erlaubt sein. Der Publish-Job verlangt `contents: write` und `packages: write` für das eingebaute `GITHUB_TOKEN`. Ein Registry-Passwort wird nicht benötigt.

### 3. Mergen und ersten Publish abwarten

Nach dem Merge laufen **Stage Checks** und danach **Publish Stage Images**. Anschließend existieren das GHCR-Package `ojs-app` und der Branch `deploy/stage`.

### 4. GHCR-Package öffentlich machen

Gehe zu **GitHub → Packages → `ojs-app` → Package settings → Change visibility → Public**. Portainer braucht dann keine Registry-Zugangsdaten.

### 5. Portainer-Stack anlegen

Lege in Portainer einen neuen Stack an: **Stacks → Add stack → Repository**.

| Feld | Wert |
|---|---|
| Name | `ojs-stage` |
| Repository URL | `https://github.com/McNamara84/ojs` |
| Repository reference | `refs/heads/deploy/stage` |
| Compose path | `docker-compose.stage.yml` |
| GitOps updates | aktiviert (Polling, z. B. 5 Minuten) |
| Re-pull image | aktiviert (falls angeboten) |

**Stack-Variablen:**

| Variable | Pflicht | Beschreibung |
|---|---|---|
| `OJS_DB_PASSWORD` | ja | Passwort des DB-Benutzers `ojs` (zufällig erzeugen) |
| `OJS_DB_ROOT_PASSWORD` | ja | MariaDB-Root-Passwort (zufällig erzeugen) |
| `MAIL_HOST` | ja | SMTP-Relay, wie im ERNIE-Stack |
| `MAIL_PORT` | nein | Standard `587` |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | nein | nur bei SMTP-Authentifizierung |
| `MAIL_ENCRYPTION` | nein | `tls` (Standard), `ssl` oder `none` |
| `MAIL_FROM_ADDRESS` | nein | Absender, Standard `noreply@gfz-potsdam.de` |
| `OJS_RUN_UPGRADE` | nein | `1` (Standard) = DB-Upgrade automatisch beim Start |
| `OJS_WORKER_WAIT_SECONDS` | nein | Wartetakt von Queue und Scheduler, Standard `30` Sekunden |
| `OJS_ALLOW_INSTALLER` | nein | `1` erlaubt den Installer auch bei halb initialisierter Datenbank (Standard `0`, siehe [Selbstheilung](production-deployment.md#selbstheilung-nach-config-verlust)) |
| `OJS_STAGE_APP_IMAGE` | nein | nur für einen Rollback (siehe unten) |

Die DB-Passwörter dürfen nach dem ersten Start **nicht mehr geändert** werden, ohne sie auch in MariaDB zu ändern. MariaDB übernimmt sie nur bei der Initialisierung.

### 6. OJS installieren (Web-Installer)

Rufe https://ojs.rz-vm182.gfz.de auf. Du wirst auf den Installer weitergeleitet.

- **Administrator:** Benutzername, Passwort und E-Mail frei wählen.
- **Sprache:** Hauptsprache `English (en)`, zusätzlich `Deutsch (de)`.
- **Zeitzone:** `Europe/Berlin`.
- **Dateiverzeichnis:** `/var/www/files`.
- **Datenbank:** Treiber `MySQLi`, Host `ojs-db`, Benutzer `ojs`, Name `ojs`, Passwort = `OJS_DB_PASSWORD`.
- **OAI-Repository-ID:** `ojs.rz-vm182.gfz.de`.

Queue und Scheduler warten bis zur Installation und starten dann automatisch, spätestens nach 30 Sekunden.

### 7. Prüfen

- In Portainer zeigen alle drei OJS-Container `ghcr.io/mcnamara84/ojs-app@sha256:…`. Im Deployment-Log gibt es keinen lokalen Build.
- `ojs-stage-app` und `ojs-stage-db` sind *healthy*, Queue und Scheduler laufen.
- Die Anmeldung funktioniert. Eine Passwort-Reset-Mail kommt an.
- ERNIE unter https://ernie.rz-vm182.gfz.de ist unverändert erreichbar.

## Normalbetrieb

Merge nach `main` → Stage Checks → Publish Stage Images → Portainer deployt. Config, Uploads und Datenbank bleiben in den Volumes erhalten.

## Manueller Retry

Öffne den erfolgreichen **Stage Checks**-Lauf des aktuellen `main`-Commits und wähle **Re-run all jobs**. Danach startet **Publish Stage Images** erneut. Der Publish-Workflow hat bewusst keinen manuellen Trigger.

## Rollback

Lies den Digest aus einem früheren `deploy/stage`-Commit ab und setze ihn als Stack-Variable:

```dotenv
OJS_STAGE_APP_IMAGE=ghcr.io/mcnamara84/ojs-app@sha256:<guter-digest>
```

Danach den Stack neu deployen. Wird die Variable wieder entfernt, gilt wieder der automatisch gepflegte Stand.

Geht der Rollback über einen Versionssprung zurück, ist die Datenbank anschließend neuer als der Code. `ojs-app` startet dann bewusst nicht und schreibt ins Log, was zu tun ist: entweder das passende Image deployen oder das zugehörige Datenbank-Backup zurückspielen. Nur wenn du die Abweichung bewusst in Kauf nimmst, startet `OJS_RUN_UPGRADE=0` den Container trotzdem.

**Achtung:** Ein OJS-Datenbank-Upgrade lässt sich nicht automatisch umkehren. Vor dem Anheben von `OJS_IMAGE_TAG` deshalb ein Backup anlegen (siehe unten). Ein Rollback über einen Versionssprung hinweg braucht das zugehörige DB-Backup.

## OJS-Version aktualisieren

1. `OJS_IMAGE_TAG` in `docker/ojs/Dockerfile` (und in `.env.example`) auf das neue PKP-Release setzen. Verfügbare Tags: https://hub.docker.com/r/pkpofficial/ojs/tags
2. Die Befunde in `.trivyignore` neu bewerten. Einträge, die das neue Release behebt, entfernen.
3. Ein DB-Backup auf Stage anlegen.
4. Einen PR öffnen und mergen. Beim ersten Start führt `ojs-app` das Datenbank-Upgrade aus (siehe Log von `ojs-stage-app`).

## Backup

```bash
docker exec ojs-stage-db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction --result-file=/tmp/ojs.sql "$MARIADB_DATABASE"'
```

```bash
docker cp ojs-stage-db:/tmp/ojs.sql ./ojs-stage-$(date +%F).sql
```

Zusätzlich die Volumes `ojs-files`, `ojs-public` und `ojs-config` sichern.

## Sicherheitsbefunde (`.trivyignore`)

Das offizielle PKP-Image bringt veraltete Debian-Pakete mit. Der Build führt deshalb `apt-get upgrade` aus, und `SYSTEM_PACKAGES_REFRESH` erzwingt das täglich neu. Übrig bleiben Befunde in PHP-Bibliotheken, die PKP mitliefert:

- **guzzle:** CVE-2026-69246.
- **commonmark:** mehrere Befunde. OJS nutzt die Bibliothek nicht, sie kommt nur über `laravel/framework` mit.

Diese Befunde stehen mit Begründung und **Ablaufdatum 21.11.2026** in `.trivyignore`. Nach Ablauf blockiert das Gate wieder und erzwingt eine Neubewertung, idealerweise durch ein neues OJS-Release.

## Fehlersuche

| Symptom | Ursache / Lösung |
|---|---|
| Portainer: `required variable OJS_DB_PASSWORD is missing` | Eine Stack-Variable fehlt (siehe Schritt 5) |
| `ojs-stage-app` startet nicht, Log `[ojs-configure] FEHLER: …` | Eine Stack-Variable ist ungültig, z. B. `MAIL_FROM_ADDRESS` oder `MAIL_ENCRYPTION`. Die Meldung nennt die Variable |
| `ojs-stage-app` bleibt *unhealthy* nach einem OJS-Update | Das Datenbank-Upgrade ist fehlgeschlagen. Log prüfen, bei Bedarf das Backup zurückspielen |
| Queue/Scheduler melden dauerhaft „DB-Upgrade laeuft“ | Code- und DB-Version gehen auseinander. Log von `ojs-stage-app` prüfen. Stehen in `versions` mehrere Zeilen mit `current = 1`, ist die Tabelle inkonsistent |
| `ojs-stage-app` startet nicht: „Die Datenbank … ist neuer als der Code …“ | Das Image wurde hinter den Stand der Datenbank zurückgerollt. Passendes Image deployen oder das zugehörige DB-Backup zurückspielen |
| Die Seite lädt, aber Links zeigen auf `http://` | Traefik sendet `X-Forwarded-Proto` nicht. Prüfen, ob der Router den Entrypoint `https` nutzt |
| 404 von Traefik | Der Container hängt nicht im Netzwerk `traefik`, oder das Label `traefik.enable=true` fehlt |
| `deploy/stage` bewegt sich nicht | Die Läufe von **Stage Checks** bzw. **Publish Stage Images** prüfen. Ein veralteter Lauf überspringt die Promotion absichtlich |
| Push nach GHCR oder `deploy/stage` scheitert mit 403 | Workflow-Rechte prüfen (Schritt 2) |
| Trivy blockiert nach dem 21.11.2026 | Die `.trivyignore`-Einträge sind abgelaufen, siehe Abschnitt „Sicherheitsbefunde“ |

# Production-Deployment (RZ-VM499)

OJS läuft in Produktion unter **https://ojs.rz-vm499.gfz.de**. RZ-VM499 baut keine Images. Ein veröffentlichter GitHub-Release befördert genau den Image-Digest nach `deploy/prod`, der zuvor auf Stage lief. Portainer folgt diesem Branch. Das Verfahren entspricht dem von ERNIE.

## Ablauf

1. Pull Request → Merge nach `main`.
2. **Stage Checks** prüft den Commit, **Publish Stage Images** baut das Image und bewegt `deploy/stage`. Stage deployt.
3. Nach ausreichender Erprobung auf Stage: GitHub-Release mit Tag `vMAJOR.MINOR.PATCH` veröffentlichen, zum Beispiel `v1.0.0`.
4. **Production Release Signal** hält das Ereignis fest. Dieser Workflow hat bewusst keine Schreibrechte.
5. **Promote Production Release** läuft aus dem Standard-Branch und prüft der Reihe nach:
   - Ist der Release der höchste stabile Release und nicht älter als der bereits deployte?
   - Liegt der Release-Commit auf `main`?
   - Waren „Stage Checks“ und „Publish Stage Images“ für genau diesen Commit erfolgreich?
   - Gibt es einen `deploy/stage`-Commit für diesen Quell-Commit, und ist dieser dessen Elternteil?
   - Existiert das Manifest noch, und ist der Digest weiterhin frei von HIGH- und CRITICAL-Befunden?
6. Erst danach schreibt der Workflow den Digest in `docker-compose.prod.yml` und schiebt `deploy/prod` per Compare-and-Swap weiter.
7. Portainer erkennt den Commit und erstellt die Container neu.

Produktion bekommt damit nie ein frisch gebautes Image, sondern exakt das Manifest, das auf Stage lief.

## Architektur

| Container | Aufgabe |
|---|---|
| `ojs-prod-app` | Apache/PHP 8.3 (OJS), Traefik-Router `ojs-prod-router` |
| `ojs-prod-queue` | Hintergrund-Jobs |
| `ojs-prod-scheduler` | geplante Aufgaben |
| `ojs-prod-db` | MariaDB 11.8, per Digest gepinnt |

| Volume | Herkunft | Inhalt |
|---|---|---|
| `ojs_db_data` | **Altbestand** | Datenbank |
| `ojs_ojs_private` | **Altbestand** | Einreichungsdateien (`/var/www/files`) |
| `ojs_ojs_public` | **Altbestand** | öffentliche Dateien |
| `ojs-prod-config` | neu | `config.inc.php` inkl. `app_key` |

Das frühere Volume `ojs_ojs_config` wird **nicht** übernommen. Es hing am falschen Pfad (`/var/www/html/config` statt der Datei `config.inc.php`) und war der Grund, weshalb die Konfiguration bei jedem Update verlorenging.

## Selbstheilung nach Config-Verlust

Beim Start prüft `ojs-configure`, ob die Datenbank bereits eine OJS-Installation enthält. Wenn ja, aber die Konfiguration sagt `installed = Off`, setzt es den Wert auf `On` und erzeugt bei Bedarf einen neuen `app_key`.

Damit kann der frühere Ausfall nicht erneut passieren: Vor einer gefüllten Datenbank erscheint nie wieder der Installer, den jemand versehentlich abschicken und damit den Bestand überschreiben könnte.

Die Prüfung ist bewusst misstrauisch. Als leer gilt die Datenbank nur, wenn die Abfrage erfolgreich war und die Tabelle `versions` fehlt, also der Normalzustand einer frischen Datenbank. Alles andere bricht den Start ab und wird im Log genannt:

| Zustand der Datenbank | Verhalten |
|---|---|
| Gar keine Tabellen | leer, der Installer ist zuständig |
| Tabelle `versions` fehlt, andere Tabellen vorhanden | Abbruch. Vermutlich falscher Datenbankname oder unvollständig eingespielter Dump. Mit `OJS_ALLOW_INSTALLER=1` freigebbar |
| Aktuelle OJS-Version vorhanden | `installed = On` wird gesetzt, bei Bedarf ein `app_key` erzeugt |
| Tabelle `versions` da, aber ohne aktuelle OJS-Version | Abbruch. Halb initialisiert oder Datenbank einer anderen PKP-Anwendung. Mit `OJS_ALLOW_INSTALLER=1` bewusst freigebbar |
| Nicht erreichbar, fehlende Rechte, sonstiger Fehler | Abbruch | Der Container startet dann in einer Neustartschleife und liefert nichts aus. Das ist gewollt: Eine vorübergehende Störung würde sonst wie eine leere Datenbank aussehen, und der Installer wäre erreichbar.

Ein neu erzeugter `app_key` bedeutet lediglich, dass sich alle Nutzer einmal neu anmelden müssen. Inhalte sind nicht betroffen.

## Statische EMTF-Sammlung (Übergangslösung)

Der Container `ojs-prod-emtf` liefert die alte EMTF-Sammlung (Elektromagnetische Tiefenforschung) unter **https://dataservices.gfz.de/emtf/** aus, damit die Adressen erreichbar bleiben, bis die Inhalte in OJS eingepflegt sind.

- **Inhalt:** rund 360 MB, überwiegend PDFs und statische HTML-Seiten. Die Sammlung umfasst Übersichten der Kolloquien von **1962 bis 2025** sowie die Tagungsbände und Einzelbeiträge der Jahre 2001, 2003, 2005, 2007 und 2009. Sie liegt im Repository unter `emtf/` und wird beim Image-Bau nach `/usr/share/nginx/html/emtf` kopiert.
- **Verweise:** `scripts/check-emtf-links.py` prüft in „Stage Checks“, ob alle lokal verlinkten Dateien vorhanden sind. Bewusst geduldete Lücken stehen mit Begründung in `ALLOWED_MISSING` im Skript, derzeit die nicht überlieferten Sammelbände 2001 und 2003 sowie Reste eines Word-Exports.
- **Pfad:** Traefik entfernt das Präfix `/emtf` **nicht**. Die Dateien liegen deshalb auch im Container unter `emtf/`. Der Grund: nginx erzeugt für Verzeichnisse ohne Schrägstrich eine Weiterleitung aus dem angefragten Pfad. Ohne Präfix zeigte sie auf `/2007/` statt auf `/emtf/2007/`.
- **Weiterleitungen:** nginx liefert sie relativ aus (`absolute_redirect off`), weil es nichts von der TLS-Terminierung durch Traefik weiß und sonst `http://`-Adressen erzeugen würde.
- **Route:** Die Regel lautet `Host(...) && (Path(`/emtf`) || PathPrefix(`/emtf/`))`. `PathPrefix` allein würde in Traefik auch `/emtf-old` oder `/emtf.html` abfangen, weil es nicht segmentweise vergleicht. Das ist nachgestellt und geprüft.
- **Priorität:** Auf `dataservices.gfz.de` existiert bereits ein Router, der auf `dataservices.gfz-potsdam.de` weiterleitet. Der EMTF-Router trägt deshalb `priority=1000` und greift für `/emtf` zuverlässig zuerst. Alle anderen Pfade bleiben unverändert beim bestehenden Router.
- **Aufgeräumt:** Die FTP-Altlasten (`WSFTP32.dll`, zwei `WS_FTP.LOG` mit internen Serverpfaden) wurden entfernt.

**Wenn die Inhalte in OJS übernommen sind**, entfernst du in einem Pull Request den Dienst `ojs-emtf` aus beiden Compose-Dateien, den Ordner `emtf/`, das Verzeichnis `docker/emtf/` sowie die zugehörigen Schritte in den Workflows. Sinnvoll ist dann eine Weiterleitung von `/emtf/` auf die neuen OJS-Adressen, damit die alten Links nicht ins Leere laufen.

## Einmalige Übernahme der Altinstanz

> **Reihenfolge einhalten.** Der neue Stack verwendet dieselben Volumes wie der alte. Lösche den alten Stack deshalb erst, wenn der neue läuft. Dann sind die Volumes in Benutzung, und Docker verweigert ein versehentliches Löschen.

### 1. Sicherung anlegen

Portainer auf RZ-VM499 → Container `ojs_db` → **Console** → `/bin/sh`:

```bash
mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --events --default-character-set=utf8mb4 --result-file=/var/lib/mysql/ojs-backup.sql "$MARIADB_DATABASE" && ls -lh /var/lib/mysql/ojs-backup.sql
```

Der Dump landet im Volume `ojs_db_data` und übersteht damit das Neuanlegen der Container. Falls ein Weg besteht, ihn vom Server herunterzuladen, sollte das zusätzlich geschehen: Eine Sicherung auf demselben Server schützt nicht gegen einen Ausfall des Servers.

### 2. Alten Stack stoppen

Portainer → **Stacks** → `ojs` → **Stop this stack**. Nicht löschen.

Das ist zugleich die Absicherung gegen den offenen Installer der Altinstanz.

### 3. Zugangsdaten des alten Stacks notieren

Portainer → Stacks → `ojs` → die vorhandenen Umgebungsvariablen. Du brauchst `OJS_DB_NAME`, `OJS_DB_USER`, `OJS_DB_PASSWORD` und `MYSQL_ROOT_PASSWORD` unverändert weiter.

**Wichtig:** MariaDB legt Benutzer und Passwörter nur bei der ersten Initialisierung an. Da das Datenverzeichnis übernommen wird, gelten weiterhin die alten Zugangsdaten. Neue Werte in den Variablen würden schlicht ignoriert und der Start scheitern.

### 4. Neuen Stack anlegen

Portainer → **Stacks → Add stack → Repository**:

| Feld | Wert |
|---|---|
| Name | `ojs-prod` |
| Repository URL | `https://github.com/McNamara84/ojs` |
| Repository reference | `refs/heads/deploy/prod` |
| Compose path | `docker-compose.prod.yml` |
| GitOps updates | an, Polling, z. B. 5 Minuten |
| Re-pull image | an |

**Stack-Variablen:**

```dotenv
OJS_DB_NAME=<wie im alten Stack>
OJS_DB_USER=<wie im alten Stack>
OJS_DB_PASSWORD=<wie im alten Stack>
OJS_DB_ROOT_PASSWORD=<MYSQL_ROOT_PASSWORD des alten Stacks>
MAIL_HOST=<wie im Stage-Stack>
MAIL_PORT=587
MAIL_USERNAME=<wie im Stage-Stack>
MAIL_PASSWORD=<wie im Stage-Stack>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@gfz-potsdam.de
```

Dann **Deploy the stack**.

Beim ersten Start passiert automatisch:
1. `ojs-configure` erzeugt die Konfiguration im neuen Volume, erkennt die vorhandene Installation und setzt `installed = On`.
2. Ein neuer `app_key` wird erzeugt.
3. Die Datenbank wird von **3.5.0.3** auf **3.5.0.5** gehoben.
4. Apache startet, Queue und Scheduler laufen an.

Das Datenbank-Upgrade dauert je nach Umfang etwas. Der Healthcheck lässt dafür bis zu zwei Minuten Anlaufzeit.

### 5. Prüfen

- Im Log von `ojs-prod-app` steht `Datenbank enthaelt bereits eine OJS-Installation`, danach `Upgrade der Datenbank von 3.5.0.3 auf 3.5.0.5` und `Successfully upgraded`.
- https://ojs.rz-vm499.gfz.de zeigt die Seite, **nicht** den Installer.
- Die Zeitschrift `etmf-bb` ist erreichbar, Einreichungen und Nutzerkonten sind vorhanden.
- Eine Datei aus einer Einreichung lässt sich herunterladen.
- Die Anmeldung funktioniert. Passwörter gelten unverändert weiter, nur bestehende Sitzungen sind beendet.

### 6. Alten Stack löschen

Erst jetzt: Portainer → Stacks → `ojs` → **Delete this stack**. Die Volumes bleiben erhalten, weil der neue Stack sie benutzt. Das Volume `ojs_ojs_config` wird nicht mehr gebraucht und kann anschließend entfernt werden.

## Normalbetrieb

1. Änderungen über Pull Requests nach `main`, Stage deployt automatisch.
2. Auf Stage prüfen.
3. Release veröffentlichen, zum Beispiel `v1.1.0`. Produktion zieht nach.

## Rollback

Digest aus einem früheren `deploy/prod`-Commit lesen und in Portainer setzen:

```dotenv
OJS_PROD_APP_IMAGE=ghcr.io/mcnamara84/ojs-app@sha256:<guter-digest>
```

Danach neu deployen. Zum Zurückkehren auf den automatischen Stand die Variable wieder entfernen.

**Achtung:** Geht der Rollback über ein Datenbank-Upgrade zurück, ist die Datenbank neuer als der Code. `ojs-prod-app` startet dann bewusst nicht und schreibt in sein Log, was zu tun ist. In dem Fall hilft nur das passende Image oder das Einspielen des Backups.

## Backup

Empfohlen ist ein regelmäßiger Dump, zumindest vor jedem Release:

```bash
docker exec ojs-prod-db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --events --result-file=/tmp/ojs.sql "$MARIADB_DATABASE"'
```

```bash
docker cp ojs-prod-db:/tmp/ojs.sql ./ojs-prod-$(date +%F).sql
```

Zusätzlich sichern: `ojs_ojs_private` (Einreichungsdateien) und `ojs-prod-config` (enthält den `app_key`).

## Fehlersuche

| Symptom | Ursache / Lösung |
|---|---|
| `ojs-prod-db` startet nicht, Log nennt Zugriffsfehler | Die Stack-Variablen weichen von den alten Zugangsdaten ab. MariaDB übernimmt sie bei bestehenden Daten nicht |
| `ojs-prod-app` startet nicht, Log: „Tabelle `versions` existiert, enthält aber keine aktuelle OJS-Version“ | Die Datenbank ist halb initialisiert oder gehört einer anderen Anwendung. Prüfen, ob das richtige Volume eingebunden ist. Ist der Installer hier wirklich gewollt: Stack-Variable `OJS_ALLOW_INSTALLER=1` |
| `ojs-prod-app` startet nicht, Log: „Datenbank … nicht erreichbar … Start abgebrochen“ | Absicht. Solange die Konfiguration `installed = Off` meldet und die Datenbank nicht sicher als leer erkannt wird, startet der Container nicht, damit kein Installer vor einer gefüllten Datenbank erscheint. Zugangsdaten und Zustand von `ojs-prod-db` prüfen |
| Es erscheint der Installer statt der Seite | Die Datenbank ist nachweislich leer (keine einzige Tabelle). **Nicht** abschicken, sondern prüfen, ob das richtige Volume eingebunden ist |
| `ojs-prod-app` bleibt *unhealthy* | Datenbank-Upgrade fehlgeschlagen. Log prüfen, notfalls Backup einspielen |
| Traefik antwortet mit 404 | Container hängt nicht im Netzwerk `traefik` oder das Label `traefik.enable=true` fehlt |
| `deploy/prod` bewegt sich nicht | Läufe von „Promote Production Release“ prüfen. Blockiert wird unter anderem, wenn es für den Release-Commit keinen `deploy/stage`-Commit gibt |

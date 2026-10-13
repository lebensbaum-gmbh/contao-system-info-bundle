# Maintend: Unix-Dateirechte in Projekt-Backups

## Format ab dieser Entwicklungsrevision

Das Projektarchiv `project.zip` enthält zusätzlich
`.maintend-permissions.json` (Schema 1). Es erfasst für **tatsächlich
gesicherte Projektdateien und Verzeichnisse** den Unix-Modus (0777-Maske)
und den Typ `file`/`dir`. Auch leere Verzeichnisse werden erfasst.

Die existierende SHA-256-Prüfsumme von `project.zip` im Backup-Manifest
deckt dadurch den Dateiinhalt **und** die Dateirechte-Metadaten ab.

Beim Restore wird die Metadatendatei **vor den ersten Änderungen** aus
dem entpackten Archiv gelesen und geprüft: Pfad-Normalisierung, relative
Whitelists, Dateitypen, numerische Modes und vollständige Übereinstimmung
mit den archivierten Dateien. Merged-Webroot-Pfade werden auf vorhandene
Symlinks untersucht. Danach werden die gesicherten Projektdateien und die
benötigten leeren Verzeichnisse wiederhergestellt; Dateirechte werden
gezielt gesetzt und Verzeichnisrechte **zuletzt** rekonstruiert.

**Nicht archiviert:** `vendor/`, `var/`, generierte
`public/assets`, `public/bundles`, `public/system` und entsprechende
`web/`-Verzeichnisse, Symlink-Ziele, private Maintend-Backups und
Jobdateien. Diese Rechte dürfen nicht pauschal überschrieben werden.
Für generierte Dateien gilt der CLI-Prozess `umask 022` (PR #10);
öffentliche Asset-Rechte werden außerdem vor Erfolg geprüft (PR #11).

## Schutz vertraulicher Dateien

`.env*`-Rootdateien werden niemals gruppen- oder weltweit lesbar
wiederhergestellt; beim Wiederherstellen wird der archivierte Mode
auf Besitzerrechte beschränkt. Private Backup-Archive, Statusdateien und
Protokolle bleiben weiterhin mit `0600` in geschützten Ordnern.
Die Sicherung umfasst keine Setuid-/Setgid-/Sticky-Bits, ACLs, Dateieigentümer
oder Gruppenzugehörigkeiten. Bei problematischen Sonderrechten wird die
Sicherung nicht stillschweigend als originalgetreu gemeldet.

## Alte Backups

Fehlt `.maintend-permissions.json`, verwendet der Restore weiterhin
den bisherigen Legacy-Weg mit seinen bisherigen Standard-Dateirechten
und geschützter `.env`. Alte Backups **können die ursprünglichen Modes
nicht nachträglich rekonstruieren**. Das Ergebnis kennzeichnet den
Unterschied ausdrücklich:

- `permissions_restored=true`, `permissions_format=manifest-v1`
- `permissions_restored=false`, `permissions_format=legacy-fallback`

Dadurch bleiben alte Sicherungen nutzbar, aber ein Restore aus ihnen
garantiert keine originalgetreuen Rechte für individuelle Projektdateien.

## Veröffentlichtes Contao-`files/` und Prüfung

Contao verlinkt ausdrücklich veröffentlichte `files/`-Unterverzeichnisse
über `public/files/<name>` beziehungsweise `web/files/<name>`. Der
`PublicAssetPermissionGuard` folgt nur solchen expliziten
Verzeichnisverknüpfungen, deren Ziel innerhalb des Projekt-`files/`
liegt. Er überprüft deren übergeordnete Verzeichnisrechte sowie
öffentliche CSS-/JS-/Bild-/Font-Dateien auf Lesbarkeit. Andere private,
nicht veröffentlichte `files/`-Ordner werden **nicht** gescannt oder
geändert. Der Guard verändert **niemals** Zugriffsrechte.

Editor-Verwaltungsordner namens `_notes` (z. B. Dreamweaver-`dwsync.xml`) sind
auch innerhalb veröffentlichter Ordner nicht für die Webausgabe bestimmt.
Der Guard überspringt diese Verzeichnisse bewusst und fordert für sie keine
öffentlichen Traverse-Rechte. Danebenliegende CSS-/JS-/Bild-/Font-Dateien
werden weiterhin geprüft. `_notes` sollte privat bleiben (z. B. 0700).


Ein früherer Restore hatte etwa `files/mate/fonts/*.woff2` mit 0600
und `files/` mit 0700 hinterlassen. Das wurde durch ältere Backups
übernommen und korrekt, aber weiterhin frontenduntauglich,
zurückgespielt. Die neue Prüfung meldet bei solchem Befund einen
Fehler anstatt „Restore erfolgreich“, ohne die Dateien automatisch
freizugeben. In dieser Situation müssen die tatsächlich öffentlich
genutzten Dateien gezielt geprüft und ihre Rechte bereinigt werden,
bevor ein verlässlicher neuer Sicherungsstand entsteht.

## Abnahme vor Produktivbetrieb

1. Neue Sicherung aus einer entbehrlichen Contao-Testinstallation erstellen.
2. Datei-/Verzeichnisrechte dokumentieren: privat `0600`, gruppenlesbar
   `0640`, ausführbar `0755`, Verzeichnis `0750` und leeres Verzeichnis.
3. Kontrolliertes Update + Restore durchführen und gespeicherte Modes
   sowie Contao-Frontend/Backend über HTTP prüfen.
4. Sicherstellen, dass öffentliche Contao-Assets HTTP 200 liefern;
   private `.env`- und Backup-Dateien dürfen nicht ausgeliefert werden.
5. Alten Backup-Stand ohne Rechte-Metadaten separat testen und das
   `legacy-fallback`-Ergebnis kontrollieren.

Diese Tests müssen auf dem realen Hosting bestanden sein, bevor das
Restore-Feature produktiv freigegeben wird.

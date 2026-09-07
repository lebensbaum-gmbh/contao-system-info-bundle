# Contao System Info

Das **Contao System Info Bundle** stellt geschützte Systeminformationen einer Contao-Installation für den **Contao Domain Manager** bereit.

Es wird auf jeder Contao-Installation installiert, die zentral überwacht oder synchronisiert werden soll.

## Funktionen

- automatische Erzeugung einer eindeutigen Installations-ID
- automatische Erzeugung eines sicheren Secrets
- geschützter System-Info-Endpunkt
- Bereitstellung von Contao-Version und PHP-Version
- Bereitstellung von Datenbankname und DocumentRoot
- Backend-Modul zur Anzeige und Verwaltung der Zugangsdaten
- Secret standardmäßig verborgen und nur auf ausdrückliche Aktion sichtbar
- Secret kann bei Bedarf neu erzeugt werden
- signierte Remote-Aktionen für Backup und Restore
- nicht-destruktive Composer-Auflösung zur Vorbereitung von Updates
- keine manuelle Bearbeitung von `.env`-, JSON- oder Composer-Dateien erforderlich

## Voraussetzungen

- PHP `^8.2`
- Contao `^4.13 || ^5.0`

Für die Update-Vorbereitung muss auf der Zielinstallation außerdem Prozessausführung über `proc_open` möglich sein und ein zur Web-PHP-Version passendes PHP-CLI zur Verfügung stehen. Bevorzugt wird der im Contao Manager konfigurierte PHP-CLI-Pfad.

## Installation

Installiere das Bundle über den **Contao Manager**:

`lebensbaum/contao-system-info-bundle`

Führe anschließend die von Contao angebotene Datenbankmigration aus.

Nach erfolgreicher Installation steht im Backend unter **System → System-Info** die Verbindungskonfiguration zur Verfügung.

## Einrichtung

Beim ersten Aufruf erzeugt das Bundle automatisch:

- eine Installations-ID
- ein Secret
- den System-Info-Endpunkt

Das Secret wird standardmäßig verborgen angezeigt. Über **Secret anzeigen** kann es für den aktuellen Seitenaufruf einmalig eingeblendet und kopiert werden. Beim nächsten Seitenaufruf ist es wieder verborgen.

Diese Daten werden benötigt, um die Installation mit dem **Contao Domain Manager** zu verbinden.

### Verbindung mit dem Domain Manager

1. Öffne auf der Zielinstallation **System → System-Info**.
2. Kopiere die Installations-ID.
3. Klicke auf **Secret anzeigen** und kopiere das Secret.
4. Öffne im Domain Manager die gewünschte Installation.
5. Trage Installations-ID und Secret ein.
6. Führe **Verbindung testen** aus.
7. Nach erfolgreichem Test können die Systeminformationen synchronisiert werden.

Bei der Synchronisation können unter anderem folgende technische Angaben übernommen werden:

- Contao-Version
- PHP-Version
- Datenbankname
- DocumentRoot

## Geschützte Remote-Aktionen

Zusätzlich zum lesenden System-Info-Endpunkt stellt das Bundle signierte Aktionsendpunkte für den Domain Manager Pro bereit. Sie sind nicht für direkte öffentliche Bedienung vorgesehen.

### Backup und Restore

Backups enthalten Datenbank und relevante Projektdateien. Vor einem Restore wird automatisch eine zusätzliche Sicherheitskopie des aktuellen Zustands erzeugt. Restore-Anfragen werden nur für bekannte und vollständig geprüfte Backups ausgeführt.

### Update vorbereiten

Die Update-Vorbereitung installiert **keine** Pakete. Sie führt eine Composer-Abhängigkeitsauflösung als Dry-Run aus und meldet dem Domain Manager, welche Paketoperationen vorgesehen wären.

Dabei gelten zusätzliche Schutzmaßnahmen:

- `composer.json` und `composer.lock` werden vor dem Dry-Run per SHA-256 geprüft.
- Der Dry-Run läuft mit `--no-install`, `--no-scripts` und `--no-plugins`.
- Nach dem Lauf werden die Composer-Dateien erneut geprüft.
- Sollten sie wider Erwarten verändert worden sein, werden die Originalinhalte wiederhergestellt und die Vorbereitung als Fehler beendet.
- Ein Wechsel des Contao-Versionszweigs wird nicht freigegeben; vorgesehen sind ausschließlich Bugfix-Updates innerhalb desselben `major.minor`-Zweigs.

Der Contao Manager wird als Composer-Treiber bevorzugt. Optional können bei ungewöhnlichen Hosting-Konfigurationen `CONTAO_SYSTEM_INFO_PHP_CLI` und `CONTAO_SYSTEM_INFO_MANAGER_PATH` gesetzt werden.

## Secret neu erzeugen

Das Secret kann im Backend jederzeit neu erzeugt werden.

Nach einer Änderung muss das neue Secret auch im zugehörigen Eintrag des Domain Managers hinterlegt werden. Das bisherige Secret ist danach nicht mehr gültig. Das neu erzeugte Secret wird einmalig angezeigt und ist nach dem nächsten Seitenaufruf wieder verborgen.

## Sicherheit

Der System-Info-Endpunkt ist nicht für eine öffentliche Nutzung vorgesehen. Der Zugriff wird über Installations-ID und Secret geschützt und die Anfrage signiert.

Das Secret wird verschlüsselt gespeichert und im Backend nicht ohne ausdrückliche Aktion im Klartext ausgegeben. Es sollte vertraulich behandelt und nur an Personen weitergegeben werden, die die Verbindung zum Domain Manager administrieren.

Es werden keine Datenbank-Zugangsdaten wie Benutzername oder Passwort übertragen. Aus der Datenbankverbindung wird ausschließlich der Datenbankname bereitgestellt.

## Domain Manager

Die zentrale Verwaltung erfolgt mit:

`lebensbaum/contao-domain-manager-bundle`

Repository:

https://github.com/lebensbaum-gmbh/contao-domain-manager-bundle

## Lizenz

Dieses Projekt ist unter der **MIT License** veröffentlicht.

Copyright (c) 2026 Lebensbaum GmbH

Siehe [LICENSE](LICENSE).

## Support und Fehlerberichte

Fehler und technische Probleme können über die GitHub-Issues des Projekts gemeldet werden:

https://github.com/lebensbaum-gmbh/contao-system-info-bundle/issues

Quellcode:

https://github.com/lebensbaum-gmbh/contao-system-info-bundle

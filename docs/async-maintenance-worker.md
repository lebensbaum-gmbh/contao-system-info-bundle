# Maintend: Wartungsaufträge per CLI (Entwicklungsstand)

## Problem

Ein Composer-Update oder Restore, das noch innerhalb des HTTP-Kernels ausgeführt wird,
kann während `cache:clear` die eigenen Symfony-Containerdateien verlieren.
Im Test am 12.10.2026 trat `Failed opening required ...var/cache/prod/Container...php`
sowohl bei `update-install` als auch bei `restore` auf.

## Neuer Ablauf

1. Ein signierter POST auf `update-install` bzw. `restore` prüft den Auftrag und
   speichert ihn mit Status `pending` in `var/domain-manager/maintenance-jobs/`.
2. Der Endpoint antwortet **202 Accepted**, nicht „erfolgreich abgeschlossen“.
3. Ein eigenständiger PHP-CLI-Prozess startet nach kurzer Entkopplung vom HTTP-Request.
4. Der Prozess führt die bisherigen validierten Service-Operationen aus
   und schreibt persistente Phasen und das vollständige Abschlussresultat.
5. Die zentrale Pro-Verwaltung fragt die signierten Endpoints
   `update-status` bzw. `restore-status` ab und akzeptiert ausschließlich
   `phase=completed`, `status=success` und ein passendes Ergebnis.
6. Ein abnormaler Prozessabbruch lässt den Zustand bewusst **unklar/running**
   statt fälschlich „erfolgreich“ erscheinen.

**Dateirechte:** Der Launcher öffnet seine privaten Job-Logs mit `umask 077`,
setzt aber im getrennten CLI-Worker vor Composer/Cache/Asset-Operationen
ausdrücklich `umask 022`. So bleiben Logs/Statusdateien mit Modus 0600
geschützt und neu erzeugte öffentliche CSS-/JS-Dateien normalerweise
mit Modus 0644 für den Webserver lesbar. Ein global restriktiver Worker-umask
hatte auf All-Inkl nach Restore HTTP 403 für
`public/bundles/contaocore/backend.9fa77d51.js` verursacht.

Der Wartungsjob speichert den Auftrag ohne API-Secret; Dateirechte 0600
und ein Verzeichnis mit 0700. Die IDs sind zufällige 128-Bit-IDs.
Status-Endpunkte bleiben signiert. Neue mutierende Aktionen werden blockiert,
solange ein anderer Wartungsjob `pending` oder `running` ist.

**Wichtig:** Dies ist Entwicklungsarbeit, keine bereits abgenommene Produktfunktion.
Ein vom Hoster beendeter Hintergrundprozess kann weiterhin einen ungeklärten Status
hinterlassen. In diesem Fall keinen zweiten Restore starten; Logs und Backup-Archive prüfen.

## All-Inkl-/Hosting-Prüfung ohne Update oder Restore

Nach Installation **nur auf der entbehrlichen Testinstallation** per SSH im
Contao-Hauptverzeichnis mit dem zur Installation passenden PHP-CLI-Binary:

```bash
php vendor/bin/contao-console maintend:maintenance-probe --env=prod --no-ansi
```

Die Probe legt einen kleinen Testauftrag an, startet ihn über denselben
Hintergrundmechanismus wie ein Restore und wartet maximal 30 Sekunden auf
dessen bestätigten Abschluss. Keine Datenbank- oder Composer-Dateien
werden verändert.

Erwartete Meldung: `OK: Hintergrundprozess gestartet und abgeschlossen.`

Wenn `php` nicht dieselbe Haupt-/Nebenversion wie das Web-PHP hat,
das passende Host-CLI verwenden bzw. `CONTAO_SYSTEM_INFO_PHP_CLI` prüfen.
Bei einem Fehlschlag die Datei `var/domain-manager/maintenance-jobs/<id>.log`
und PHP-CLI-/Hosting-Berechtigungen prüfen. Keine Sicherung löschen.

## Abnahmereihenfolge

1. Testsystemzustand/Backups und Speicher kontrollieren.
2. **Zuerst Pro (und Core) auf der zentralen Testverwaltung** aktualisieren.
   Das neue Pro akzeptiert sowohl ältere synchrone als auch neue asynchrone
   Agent-Antworten; die Umkehrreihenfolge wäre zwischenzeitlich inkompatibel.
3. **Danach System Info ausschließlich auf der Test-Zielinstallation**
   aktualisieren und nur die ungefährliche Probe ausführen.
4. Erst nach erfolgreicher Probe neues Backup und Update auf Testinstallation durchführen; Zielversion
   und verifizierten Serverstatus prüfen.
5. Restore inklusive phasenbezogener Rückmeldungen testen; Paketstand,
   DB, Files, Cache, System-Info-Agent, Erreichbarkeit und neues
   `pre_restore`-Backup abgleichen.
6. Zusätzlich Netzwerkunterbrechung und parallelen Wartungsversuch testen.

Kein Merge auf `main` oder Rollout auf Produktivinstallationen,
bevor diese Abnahme erfolgreich abgeschlossen ist.

## Bekannte Grenzen

- Der Pro-Frontend-POST wartet weiterhin serverseitig auf den abgeschlossenen
  Remote-Auftrag. Browser/FPM-Timeouts können deshalb den zentralen Status
  vorzeitig „unklar“ lassen, auch wenn der getrennte Worker weiterläuft.
  Die persistenten Ziel-Statusdateien bleiben für eine spätere
  Status-/Wiederherstellungsabfrage erhalten.
- Ein abgebrochener Worker kann seinen Job als `running` zurücklassen.
  Die Wartungswarteschlange sperrt bewusst weitere Änderungen;
  manuelle Prüfung und freigegebener Recovery-Ablauf sind nötig.
- Hintergrundjobs benötigen nutzbares `proc_open`, `/bin/sh`, `nohup`,
  ein zu Web-PHP passendes CLI sowie Hosting-Erlaubnis für Kindprozesse.
- Die Remote-Status-Abfrage darf vorübergehend fehlschlagen, während
  Composer den Zielkernel neu aufbaut. Dies beweist weder Erfolg noch Fehler.

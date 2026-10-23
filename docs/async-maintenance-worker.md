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

## Restore: Agent-Erhalt ohne unnötigen GitHub-Zugriff

Nachdem der Restore die archivierte `composer.lock` installiert und den
Produktionscache aufgebaut hat, kontrolliert der Agent-Erhalt zunächst lokal:
die direkte Composer-Anforderung, die gesperrte Paketversion mit exaktem
Commit und die frisch eingelesene `vendor/composer/installed.php`.
Nur wenn **alle** Werte dem vor dem Restore festgehaltenen System-Info-Stand
entsprechen und eine eindeutige Commit-Referenz vorliegt, wird die zusätzliche
Composer-Update-/Dry-Run-Phase übersprungen. Bei jeder Abweichung oder
unvollständigen Metadaten bleibt die bestehende strenge Composer-Prüfung aktiv.

Hintergrund: Beim Integrationstest am 13.10.2026 waren Projektdateien,
Dateirechte und Datenbank bereits erfolgreich zurückgesetzt; der abschließende
redundante Composer-Dry-Run schlug aber mit
`Could not authenticate against github.com` fehl und verhinderte zu Recht
eine erfolgreiche Restore-Bestätigung.

Ein bereits als `error` gespeicherter Job bleibt **fehlgeschlagen** und wird
durch die Korrektur nicht nachträglich als erfolgreich markiert. Vor erneutem
Restore zunächst Testinstallation und Sicherungen unabhängig prüfen.

## Wartungsseite und Notfallzugriff

Update und Restore schützen die regulären Contao-Webzugriffe mit einer exklusiv
angelegten `var/maintenance.html`. Das betrifft auch die signierten Statusrouten.
Die passende Pro-Version erkennt ausschließlich die gekennzeichnete Maintend-
Wartungsseite als vorübergehend nicht verfügbaren Status. Sie fragt den Status
innerhalb der bestehenden Gesamtgrenze von 900 Sekunden erneut ab. Die Seite
selbst bestätigt weder Identität noch Ergebnis eines Auftrags. Neue Aktionen
bleiben abgewiesen und werden nicht automatisch erneut gestartet.

Nach Erfolg wird nur die unveränderte eigene Seite entfernt, bevor der Worker
terminalen Erfolg veröffentlicht. Bei einem echten Update-/Restore-Fehler oder
hartem Prozessabbruch bleibt sie zur manuellen Klärung stehen. Eine absichtlich
fehlgeschlagene Probe mit `--maintenance-page --fail` räumt ihre eigene Seite auf.
Vorhandene oder zwischenzeitlich fremd veränderte Seiten werden nie entfernt.

Falls die Website im Wartungsmodus bleibt:

1. Über SSH bzw. die unabhängig vom normalen Website-Einstieg erreichbare
   Contao-Manager-PHAR den echten Prozesszustand und den Auftrag unter
   `var/domain-manager/maintenance-jobs/<request-id>.json` prüfen. Keine zweite
   Wartung starten und keine ungeklärte Statusdatei löschen.
2. Composer-/Vendor-Stand, Datenbank und das vorgeschaltete Sicherheitsbackup
   prüfen; nötigenfalls den freigegebenen Wiederherstellungsweg nutzen.
3. Erst nach beendeter Wartung und repariertem Stand den Produktionscache mit
   dem passenden PHP-CLI aufbauen und das Ergebnis prüfen. Anschließend nur die
   von diesem Vorgang stammende Wartungsseite gezielt freigeben, z. B. mit
   `php bin/console contao:maintenance-mode disable`. Eine manuell gesetzte
   Wartungsseite bleibt unter der Kontrolle ihres ursprünglichen Betreibers.
4. Frontend, Backend und signierte Statusabfrage erneut kontrollieren. Ein
   gespeicherter Fehlerstatus wird durch die Reparatur nicht rückwirkend zu Erfolg.

Die Integrationstests verwenden die unveränderten, mit Lizenzhinweis
aufgenommenen Frontcontroller von Contao 4.13.0 und 5.7.15. Sie prüfen mit einem
echten PHP-HTTP-Prozess, dass auch bei fehlendem Composer-Autoloader alle regulären
Routen vor dem Bootstrap HTTP 503 liefern und nach Freigabe wieder booten.

## Console-Abschluss nach einem Cachewechsel

Der getrennte CLI-Worker bootet selbst noch einen Symfony-Container vor dem
Composer-/Cachewechsel. Die Console-Anwendung dispatcht `console.terminate`
erst nach der Rückkehr des Befehls, auch nachdem dieser bereits seinen
Erfolgsstatus geschrieben hat. Ein dann erstmals aufgelöster Listener kann
deshalb noch eine inzwischen gelöschte Factory aus dem alten Container benötigen.
Der FOS-HTTP-Cache-Invalidierungslistener ist auch für dieses Console-Ereignis
registriert; die Wartungsseite verhindert diesen CLI-Zugriff nicht.

`MaintenanceConsoleLifecycleSubscriber` löst ausschließlich für
`maintend:maintenance-job` die Listener von `console.terminate` und
`console.error` vor der Befehlsausführung auf. Auch ein von FrameworkBundle
verwendeter `LazyCommand` wird erkannt. Es werden keine Ereignisse vorzeitig
ausgeführt, keine Listener entfernt und keine Ausnahmen unterdrückt.

Der Regressionstest verwendet eine vollständige Symfony-Console-Anwendung und
einen in mehrere Dateien kompilierten DI-Container. Während des Ablaufs werden
dessen Cachedateien entfernt. Der Kontrollfall ohne Schutz erwartet den
fehlenden `getFosHttpCache_EventListener_InvalidationService.php`-Zugriff nach
einem bereits erfolgreichen Auftrag. Die geschützten Fälle prüfen normalen
Abschluss, absichtlichen Probe-Abbruch, den LazyCommand und weiterhin ausführbare
Fehlerbehandlung. Andere Befehle behalten die verzögerte Listener-Erzeugung.

Realer Test auf ALL-INKL am 19.10.2026 mit Pro `1b4b2e7` und Agent `c1867c0`:
Wartungsseiten wurden bei Probe und Restore für Frontend und Backend angezeigt.
Die Wiederherstellung auf 5.7.13 wurde auf install1 als abgeschlossen bestätigt;
Composer meldete weiterhin den Agenten `c1867c0`. Der erneute Updateplan enthielt
elf Änderungen mit Ziel 5.7.15. Anschließend bestätigt der Nutzer ein erfolgreiches
Update und wieder erreichbares Frontend und Backend; der Audit meldet keine
bekannten Sicherheitslücken. Dennoch enthalten die Logs neue Fehler um
19.43.22 und 19.49.35 Uhr mit fehlender FOS-Invalidierungsfactory. Die reale
Cache-Abnahme ist damit nicht bestanden. Der Console-Abschluss ist ein gezielt
reproduzierbarer zusätzlicher Fehlerweg; seine Zuordnung zu den konkreten
Hoster-Prozessen und die Wirkung der Korrektur sind noch real zu prüfen.

## Restore mit geändertem Agenten- und Containerstand

Der reale Restore vom 20.10.2026 auf mirror2 (Auftrag
`73ed4a578f600507cd9c228ad307a7af`) endete um 07:03:44 Uhr MESZ nach
elf Sekunden als `error`. Die Wartungsseite blieb entsprechend dem
Fehlerverhalten erhalten. Die Zentrale zeigte weiterhin den zunächst
angenommenen Auftrag, weil ihre Statusabfrage ebenfalls die Wartungsseite
erhielt. Das ist kein Nachweis für einen noch laufenden Worker.

`composer install --no-scripts` hatte den archivierten Agenten installiert.
Der nachfolgende Aufruf `contao-console cache:clear` bootete jedoch zunächst
den vorhandenen Container, der noch die im Backup-Agenten fehlende Klasse
`MaintenanceConsoleLifecycleSubscriber` erwartete. Der Agent-Erhalt wurde
dadurch nicht mehr erreicht. Der neue CLI-Abschlussschutz allein deckt diesen
Versionswechsel beim Start eines weiteren PHP-Prozesses nicht ab.

Beide Composer-Schritte des Restores verwenden deshalb für den anschließenden
Aufbau jetzt `vendor/bin/contao-setup`. Dieser offizielle Einstieg entfernt
den alten Produktionscache vor dem Kernel-Boot und erzeugt Cache, Assets und
Symlinks für den jeweiligen Vendor-Stand neu. Das gilt sowohl nach der
Installation der archivierten Sperrdatei als auch nach dem anschließenden
gezielten Agent-Erhalt. Ein nicht vorhandenes oder fehlgeschlagenes Setup
bricht weiterhin ab; `cache_rebuilt=true` wird erst nach Erfolg zurückgegeben.

Die Regression startet echte PHP-Kindprozesse mit einem kompilierten
Symfony-Container, dessen Agentenklasse beim simulierten Composer-Wechsel
entfernt wird. Der Kontrollfall reproduziert den Fehler beim Kernel-Boot vor
`cache:clear`. Die unveränderten, mit Lizenzhinweis übernommenen Setup-Einstiege
von Contao 4.13.0 und 5.7 werden mit isolierten Kernel-/Application-Partnern
ausgeführt. Sie sichern die Reihenfolge des Cacheentfernens vor dem Booten
in beiden Composer-Schritten sowie die Weitergabe von Setup-Fehlern ab.
Diese Tests ersetzen weder einen vollständigen Contao-Restore mit Datenbank
noch dessen erneute Abnahme auf dem Zielserver.

Ein gespeicherter Fehler wird durch einen manuellen Wiederanlauf nicht
nachträglich zu Erfolg. Zunächst den Agentenstand und das Contao-Setup
reparieren und prüfen; erst anschließend die zugehörige Wartungsseite
freigeben. Keinen neuen Restore zur Behebung dieses Fehlers starten.

## Shared Hosting ohne nohup (netcup)

Die netcup-Probe vom 23.10.2026 scheiterte vor dem Worker-Start mit
`nohup: command not found`. Auch `setsid` fehlt im getesteten SSH-Chroot.
Der Launcher setzt deshalb den POSIX-Shell-Trap `trap '' HUP` im
abgetrennten Kindprozess, bevor er die Worker-Shell und anschließend PHP
per `exec` startet. Die ignorierte SIGHUP-Behandlung wird vererbt.
Standard-Eingabe sowie beide Ausgabekanäle bleiben vom aufrufenden Prozess
getrennt. Private Logrechte und der Worker-umask bleiben erhalten.

Ein Regressionstest beschränkt PATH auf `sleep`, startet den echten
PHP-Worker, sendet ihm nach dem Start SIGHUP und prüft seinen Abschluss
sowie die Rechte von Log und erzeugter Datei. Die reale Probe auf netcup
und der spätere Start über HTTP müssen nach dem Agenten-Update erneut
bestätigt werden. Hosting-seitige Prozesslimits werden damit nicht umgangen.

## Bekannte Grenzen

- Der Pro-Frontend-POST wartet weiterhin serverseitig auf den abgeschlossenen
  Remote-Auftrag. Browser/FPM-Timeouts können deshalb den zentralen Status
  vorzeitig „unklar“ lassen, auch wenn der getrennte Worker weiterläuft.
  Die persistenten Ziel-Statusdateien bleiben für eine spätere
  Status-/Wiederherstellungsabfrage erhalten.
- Ein abgebrochener Worker kann seinen Job als `running` zurücklassen.
  Die Wartungswarteschlange sperrt bewusst weitere Änderungen;
  manuelle Prüfung und freigegebener Recovery-Ablauf sind nötig.
- Hintergrundjobs benötigen nutzbares `proc_open`, `/bin/sh`, `sleep`,
  ein zu Web-PHP passendes CLI sowie Hosting-Erlaubnis für Kindprozesse.
- Die Remote-Status-Abfrage darf vorübergehend fehlschlagen, während
  Composer den Zielkernel neu aufbaut. Dies beweist weder Erfolg noch Fehler.

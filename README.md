# Contao System Info Bundle

Geschützter Systeminfo-Endpunkt für die zentrale Contao-Domainverwaltung.

## Version 1.1.0

Das Bundle stellt jeder überwachten Contao-Installation eigene Zugangsdaten für die zentrale Domainverwaltung bereit.

### Zugangsdaten

- Frische Installationen erzeugen automatisch eine 32-stellige Installations-ID und ein 64-stelliges Secret.
- Das Secret wird verschlüsselt in `tl_system_info_settings` gespeichert.
- Bei einer frischen Installation wird das neu erzeugte Secret im Backend angezeigt, bis es ausdrücklich über **Secret ausblenden** verborgen wird.
- **Neues Secret erzeugen** erzeugt ein neues Secret, zeigt es mit Kopiermöglichkeit an und lässt die Installations-ID unverändert.
- Bestehende Werte aus `CONTAO_SYSTEM_INFO_ID` und `CONTAO_SYSTEM_INFO_SECRET` werden beim ersten Zugriff übernommen. Ein bestehendes Secret wird dabei nicht erneut im Klartext offengelegt.

Nach erfolgreicher Übernahme werden die alten Umgebungsvariablen für den regulären Betrieb nicht mehr benötigt.

## Backend

Das Modul befindet sich unter **System → System-Info**.

Dort stehen zur Verfügung:

- Installations-ID mit Kopiermöglichkeit
- Status des Secrets
- einmalige Anzeige neu erzeugter Secrets
- Secret-Rotation
- System-Info-Endpunkt

## Endpunkt

`/_domainverwaltung/systeminfo`

Der Abruf erfolgt über eine HMAC-SHA256-signierte Anfrage der zentralen Domainverwaltung. Antworten werden nicht gecacht und sind für Suchmaschinen gesperrt.

## Kompatibilität

- PHP `^8.2`
- Contao `^4.13 || ^5.0`
- Composer Runtime API `^2.0`

## Upgrade von 1.0.0

Beim ersten Zugriff nach der Datenbankmigration werden vorhandene Zugangsdaten aus den bisherigen Umgebungsvariablen übernommen. Die Installations-ID bleibt unverändert; das bereits verwendete Secret wird nicht erneut im Klartext angezeigt.

## Geprüfter Funktionsstand

Erfolgreich getestet wurden:

- Upgrade einer bestehenden Installation mit Erhalt von Installations-ID und Secret
- automatische Erzeugung von ID und Secret bei einer frischen Installation
- einmalige Klartextanzeige und Kopieren eines neu erzeugten Secrets
- erneute Secret-Rotation
- HMAC-Verbindung zur zentralen Domainverwaltung
- Synchronisation von Contao- und PHP-Versionen

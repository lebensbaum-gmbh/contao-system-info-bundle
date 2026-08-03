# Contao System Info Bundle

Geschützter Systeminfo-Endpunkt für die zentrale Contao-Domainverwaltung der Lebensbaum GmbH.

Das Bundle liefert ausschließlich freigegebene technische Angaben wie:

- Installations-ID
- Contao-Version
- PHP-Version
- Symfony-Umgebung
- Zeitpunkt der Antwort

Der Abruf erfolgt über eine zeitlich begrenzte HMAC-signierte Anfrage.

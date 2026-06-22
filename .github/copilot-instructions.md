# IP-Symcon PHP Module – Copilot Instructions

## Ziel
Dieses Repository enthält ein IP-Symcon PHP Modul für PHP >= 8.1.

Der Code muss:
- Store-konform sein
- Symcon SDK korrekt verwenden
- Visualisierung mittels HTML-SDK
- stabil, wartbar und produktionsreif sein
- strikt den definierten Projektstandards folgen

---

## Offizielle Referenzen (verbindlich)

Diese Quellen gelten als technische Grundlage:

- Symcon Best Practices  
  https://gist.github.com/paresy/236bfbfcb26e6936eaae919b3cfdfc4f

- Symcon Store Richtlinien  
  https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/store/einreichen/

- SymconStubs (Testing)  
  https://github.com/symcon/SymconStubs

---

## PHP Version & Grundlagen

- Minimum PHP 8.1
- Immer `<?php` verwenden (kein `<?`)
- Strikte Typisierung (`declare(strict_types=1);`)
- Kein Legacy Code

---

## COPILOT BEHAVIOR
- Bestehender Code im Repository definiert Stil und Priorität
- Keine Regelverletzungen zugunsten kürzerem oder „modernerem“ Code
- Konsistenz ist wichtiger als lokale Optimierung

---


## ARCHITEKTURREGELN (durch die Symcon Store-Prüfung durchgesetzt)

### Lebenszyklus einer Instanz
- In `Create()` und `ApplyChanges()`: Überprüfen Sie immer, ob `IPS_GetKernelRunlevel() === KR_READY` ist, bevor Sie auf andere Instanzen zugreifen.
- Alternativ: Registrieren Sie sich über `RegisterMessage` für `IPS_KERNELSTARTED` und verarbeiten Sie die Nachricht in `MessageSink`.
- Rufe niemals `SendDataToParent` / `SendDataToChildren` auf, bevor der Kernel bereit ist

### Objektzugriff
- Ein Modul darf nur Objekte ändern, die **direkt unter ihm liegen**
- Verwende niemals `SetValue` für Variablen, die zu anderen Instanzen gehören
- Suche Objekte niemals anhand ihres Namens – lege immer ein **Ident** fest und verwende dieses

### Souveränität des Benutzers
- Variablen im Archiv niemals automatisch aktivieren – das ist die Entscheidung des Benutzers
- Instanzen niemals automatisch erstellen (Ausnahme: `RequireParent`/`ConnectParent`/`ForceParent`)
- `IPS_SetProperty` oder `IPS_ApplyChanges` niemals aus dem Modul heraus aufrufen
- Ändere niemals Name, Position, Symbol oder Sichtbarkeit von Objekten nach ihrer anfänglichen Erstellung
- Lege niemals benutzerdefinierte Profile, benutzerdefinierte Aktionen oder Protokollierung für Variablen fest – auch nicht zu Beginn

### Datenfluss
- Die gesamte Kommunikation zwischen Instanzen muss den offiziellen Datenfluss nutzen:
  `SendDataToParent`, `SendDataToChildren`, `ReceiveData`, `ForwardData`
- Setze `SetReceiveDataFilter` und `SetForwardDataFilter`, um die Systemlast zu reduzieren

### Puffer
- Temporäre/interne Daten, die der Benutzer nicht benötigt: Verwende `SetBuffer`/`GetBuffer` (JSON-kodiert für mehrere Werte)
- Gib den internen Zustand nicht als Moduleigenschaften oder sichtbare Variablen preis

### Fehlerbehandlung
- Unterdrücke Fehler mit `@` nur, wenn der Rückgabewert überprüft wird und der Benutzer im Fehlerfall benachrichtigt wird
- Verwende `$this->LogMessage(...)` für die Protokollierung – niemals `IPS_LogMessage`

## library.json / module.json
- Verwenden Sie ausschließlich Felder, die in der offiziellen Symcon-Dokumentation definiert sind
- Keine benutzerdefinierten/zusätzlichen Felder (für zukünftige Symcon-Anwendungen reserviert)

## Externe Abhängigkeiten
- Alle externen Bibliotheken müssen im dafür vorgesehenen Ordner `libs/` enthalten sein
- Ein Modul darf niemals davon abhängen, dass eine andere Bibliothek installiert ist (keine fehlerhaften Einbindungen)
- Optionale Funktionen können durch zusätzlich installierte Bibliotheken freigeschaltet werden – das Modul muss jedoch auch ohne diese funktionieren und installierbar sein

## Benutzerfreundlichkeit
- Objekte sollten nur **direkt** unter der Instanz angelegt werden – keine tiefe Verschachtelung
- Alle umschaltbaren Funktionen sollten über den Aktionsbereich des Konfigurationsformulars testbar sein
- Verwende nach Möglichkeit `RequestAction` anstelle von öffentlichen Umschaltfunktionen

## Anforderungen für die Einreichung im Store
- Der Modulname darf nicht „IPSymcon“, „IPS“ oder Ähnliches enthalten
- Mindestens eine Lokalisierung ist erforderlich; alle unterstützten Sprachen müssen vollständig übersetzt sein
- Die README-Datei pro Modul muss Folgendes enthalten: Funktionsübersicht, Voraussetzungen, Symcon-Version, Modul-URL, Konfigurationsoptionen, exportierte PHP-Funktionen
- Die übergeordnete README-Datei muss alle Module der Bibliothek mit kurzen Beschreibungen auflisten
- Automatisierte Tests über SymconStubs werden empfohlen: https://github.com/symcon/SymconStubs

### Namenskonventionen
- **Konstanten**: Großbuchstaben mit Unterstrichen, z. B. `MAX_RETRY_COUNT`, `BUFFER_SIZE`
- **Eigenschaften (Modul-Eigenschaften)**: Großbuchstaben mit Unterstrichen, z. B. `HOST_ADDRESS`, `UPDATE_INTERVAL`
- **Funktionsnamen**: PascalCase, z. B. `GetName`, `ApplyChanges`, `SendData`
- **Lokale Variablen**: CamelCase, z. B. `$responseData`, `$instanceId`
- **Profile**: Format PREFIX.NAME, z. B. `MyModule.Status`; instanzspezifische Profile fügen die Instanz-ID an: `MyModule.Status.12345`

### Basisklasse (ABSOLUT VERPFLICHTEND)

Jedes Modul MUSS von `IPSModuleStrict` ableiten.

```php
class MyModule extends IPSModuleStrict
{
}
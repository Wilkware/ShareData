# 🖇️ Datenaustausch (Share Data)

[![Version](https://img.shields.io/badge/Symcon-PHP--Modul-red.svg?style=flat-square)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Product](https://img.shields.io/badge/Symcon%20Version-8.1-blue.svg?style=flat-square)](https://www.symcon.de/produkt/)
[![Version](https://img.shields.io/badge/Modul%20Version-2.0.20260929-orange.svg?style=flat-square)](https://github.com/Wilkware/ShareData)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg?style=flat-square)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Actions](https://img.shields.io/github/actions/workflow/status/wilkware/ShareData/ci.yml?branch=main&label=CI&style=flat-square)](https://github.com/Wilkware/ShareData/actions)

Leichtgewichtiges Modul zum Teilen von Variablen und Medien zwischen zwei (oder mehr) IP-Symcon Systemen über MQTT.

## Inhaltsverzeichnis

1. [Funktionsumfang](#user-content-1-funktionsumfang)
2. [Voraussetzungen](#user-content-2-voraussetzungen)
3. [Installation](#user-content-3-installation)
4. [Einrichten der Instanzen in Symcon](#user-content-4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Darstellungen](#user-content-5-statusvariablen-und-darstellungen)
6. [Visualisierung](#user-content-6-visualisierung)
7. [PHP-Befehlsreferenz](#user-content-7-php-befehlsreferenz)
8. [Versionshistorie](#user-content-8-versionshistorie)

### 1. Funktionsumfang

Der Datenaustausch-Konfigurator verbindet zwei oder mehr Symcon Instanzen über einen gemeinsamen MQTT Broker, indem ausgewählte Variablen und Medienobjekte auf Topics gemappt werden. Pro Eintrag bestimmt die Richtung (publish, subscribe, publish+subscribe) ob der Wert gesendet, empfangen oder bidirektional synchronisiert wird. Ein integrierter Ping-Pong-Schutz verhindert dabei Rückkopplungsschleifen bei bidirektionalen Einträgen.

#### Konzept

```
System A                          MQTT Broker                    System B
─────────                         ───────────                    ────────
Variable "Temperatur"  →  symcon/share/temp/aussen  →  Lokale Variable "Temp_A"
Variable "Licht EG"    ↔  symcon/share/licht/eg     ↔  Lokale Variable "Licht_B"
```

Auf **System A** werden Variablen als *Shared Variables* eingetragen → das Modul published deren Werte auf MQTT.  
Auf **System B** werden dieselben Topics als *Mapped Variables* auf lokale Variablen gemappt → eingehende MQTT-Nachrichten setzen die lokalen Variablen.

#### RequestAction vs SetValue

Das Modul entscheidet automatisch:

Bedingung                     | Methode
----------------------------- | -------------------------------------------
Variable hat `VariableAction` | `RequestAction()` – korrekte Ausführung der verknüpften Aktion (z.B. Lampe dimmen)
Keine Aktion verknüpft        | `SetValue()` – direktes Schreiben

**Warum nicht immer RequestAction?**  
`RequestAction` erfordert eine verknüpfte Instanz. Auf reinen "Datenvariablen" (z.B. berechnete Werte) gibt es keine Aktion → `SetValue` ist korrekt.

#### Hinweise

- Die übergeordnete MQTT Client-Instanz muss die geteilten Topics abonniert haben (Standard `#`, alternativ mindestens `<Präfix>#`, z. B. `symcon/share/#`). Das Modul selbst abonniert keine Topics.
- Ist der MQTT Client nicht verbunden, zeigt die Instanz den Status _Nicht verbunden_. Nach dem Wiederverbinden werden alle Objekte erneut publiziert (sofern _Alle Objekte beim Start publizieren?_ aktiv ist).
- Alle Topics nutzen `Retain = true` → der Empfänger bekommt den letzten Wert sofort nach Connect
- Das Modul registriert Variablen dynamisch im `MessageSink` – kein Kernel-Neustart nötig nach Konfigurationsänderung
- Es erfolgt keine Prüfung der Objektkompatibilität, d.h. kein Test ob die zu synchronisierenden Variablen vom gleichen Typ sind!
- Medieninhalte werden Base64-kodiert übertragen (ab v2.0, nicht kompatibel zu älteren Versionen – alle beteiligten Systeme gleichzeitig aktualisieren!)
- Ping-Pong-Schutz: Wird ein Objekt über MQTT geschrieben, wird genau die eine Aktualisierung mit diesem Wert nicht erneut publiziert; lokale Änderungen auf einen anderen Wert werden immer sofort gesendet. Das eigene Echo des Brokers wird einmalig verworfen.

### 2. Voraussetzungen

* IP-Symcon ab Version 8.1
* Getestet mit verschiedenen Variablen und Bildern < 100kb

### 3. Installation

* Über den Modul Store das Modul _Datenaustausch Konfigurator_ installieren.
* Alternativ Über das Modul-Control folgende URL hinzufügen.  
`https://github.com/Wilkware/ShareData` oder `git://github.com/Wilkware/ShareData.git`

### 4. Einrichten der Instanzen in Symcon

__Konfigurationsseite__:

Einstellungsbereich:

> 🔗 Verbindung ...

Name                                 | Beschreibung
------------------------------------ | -----------------------------------------------------------------
Topic-Präfix                         | Ist das grundlegende Themenpräfix, unter dem alle spezifischen Subtopics für Nachrichten in einem MQTT-System organisiert werden. Standardmäßig ist der Präfix auf _'symcon/share/'_ vorbelegt.

> 📊 Variablen ...

Name                                 | Beschreibung
------------------------------------ | -----------------------------------------------------------------
Variable                             | Symcon Variable auswählen
MQTT Sub-Topic                       | Topic ohne Präfix, z.B. `temp-aussen`
Richtung                             | `Publish` = nur senden, `Subscribe` = nur empfangen, `Publish + Subscribe` = bidirektional
Bei Aktualisierung synchronisieren   | Auch nur bei Änderung von Metadaten (Timestamp, keine Werteänderung) senden

> 🖼️ Medien ...

Name                                 | Beschreibung
------------------------------------ | -----------------------------------------------------------------
Medienobjekt                         | Symcon Medienobjekt auswählen
MQTT Sub-Topic                       | Topic ohne Präfix, z.B. `snapshot`
Richtung                             | `Publish` = nur senden, `Subscribe` = nur empfangen, `Publish + Subscribe` = bidirektional

> ⚙️ Erweiterte Einstellungen ...

Name                                 | Beschreibung
------------------------------------ | -----------------------------------------------------------------
Alle Objekte beim Start publizieren? | Option, ob alle konfigurierten Objekte beim Start von Symcon publiziert werden sollen

_Aktionsbereich:_

> 🚀 Aktion ausführen ...

Aktion                              | Beschreibung
----------------------------------- | -----------------------------------------------------------------
ALLE OBJEKTE JETZT PUBLIZIEREN      | Löst eine Momentaufnahme(Snapshot) aus.

### 5. Statusvariablen und Darstellungen

Es werden keine zusätzlichen Statusvariablen/Darstellungen benötigt.

### 6. Visualisierung

Es ist keine weitere Steuerung oder gesonderte Darstellung integriert.

### 7. PHP-Befehlsreferenz

Das Modul stellt keine direkten Funktionsaufrufe zur Verfügung.

### 8. Versionshistorie

v2.0.20260929

* _NEU_: Medien werden jetzt Base64-kodiert übertragen (nicht kompatibel zu v1.x – alle Systeme gleichzeitig aktualisieren!)
* _NEU_: Instanz-Status zeigt die Verbindung zum MQTT Client, nach dem Wiederverbinden wird automatisch neu publiziert
* _NEU_: Warnung bei leeren oder doppelten Topics
* _NEU_: Neue Einträge starten mit Richtung _Publish_, Medienauswahl zeigt nur Medienobjekte
* _FIX_: Rückkopplungsschleifen bei bidirektionalen Einträgen behoben
* _FIX_: Option _Alle Objekte beim Start publizieren?_ wird beim Systemstart beachtet
* _FIX_: Genauigkeit von Float-Werten und Fehlerbehandlung beim Empfang verbessert
* _FIX_: Diverse Text- und Übersetzungsfehler korrigiert

v1.1.20260817

* _NEU_: Synchronisierung nach Systemstart verbessert
* _NEU_: Namespaces für Bibliotheken eingeführt
* _FIX_: Instanz-Status wird jetzt korrekt gesetzt

v1.0.20260614

* _NEU_: Initialversion

## Entwickler

Seit nunmehr über 10 Jahren fasziniert mich das Thema Haussteuerung. In den letzten Jahren betätige ich mich auch intensiv in der IP-Symcon Community und steuere dort verschiedenste Skript und Module bei. Ihr findet mich dort unter dem Namen @pitti ;-)

[![GitHub](https://img.shields.io/badge/GitHub-@wilkware-181717.svg?style=for-the-badge&logo=github)](https://wilkware.github.io/)

## Spenden

Die Software ist für die nicht kommerzielle Nutzung kostenlos, über eine Spende bei Gefallen des Moduls würde ich mich freuen.

[![PayPal](https://img.shields.io/badge/PayPal-spenden-00457C.svg?style=for-the-badge&logo=paypal)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=8816166)

## Lizenz

Namensnennung - Nicht-kommerziell - Weitergabe unter gleichen Bedingungen 4.0 International

[![Licence](https://img.shields.io/badge/License-CC_BY--NC--SA_4.0-EF9421.svg?style=for-the-badge&logo=creativecommons)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

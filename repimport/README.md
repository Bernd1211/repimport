# REP Rechnungsimport

**REP Rechnungsimport** ist ein Dolibarr-Modul zur Unterstützung beim Import von Lieferantenrechnungen aus PDF-Dateien.

**Version 0.6.3 – Entwicklungs-/Beta-Version**

Die Versionsnummer 1.0 ist für eine später ausgereifte und stabile Community-Version vorgesehen.

## Ziel

Lieferantenrechnungen sollen möglichst weitgehend automatisch ausgelesen und für eine manuelle Kontrolle in Dolibarr vorbereitet werden. Der Import ist bewusst kein Blindimport: Die erkannten Daten werden vor dem endgültigen Anlegen geprüft und können korrigiert werden.

## Funktionen

- Analyse von PDF-Lieferantenrechnungen
- automatische Erkennung von Lieferanten und Rechnungsdaten
- Erkennung von Rechnungsnummer, Rechnungsdatum, Bestellnummer und Kundennummer, soweit im Dokument vorhanden
- Erkennung der Rechnungspositionen
- Factur-X/ZUGFeRD-Unterstützung
- lokaler KI-Fallback über LM Studio
- konfigurierbares KI-Modell
- konfigurierbarer LM-Studio-Endpunkt und Timeout
- Sammellieferanten, z. B. Amazon, eBay oder Zalando
- Ausschluss von Versandkosten, Porto, Zuschlägen und ähnlichen Nebenkosten aus den Artikelpositionen
- Zusammenfassung der Positionen einer Rechnung zu einer einzigen freien Dolibarr-Rechnungszeile
- Zuordnung des Original-PDFs zur angelegten Lieferantenrechnung
- Einzelanalyse und Batchanalyse
- Erkennung bereits analysierter Dateien
- erneute Verarbeitung fehlerhafter oder abgebrochener Analysen
- Anzeige der Berechnungszeit

## Verarbeitung

Nach Möglichkeit werden strukturierte Factur-X/ZUGFeRD-Daten direkt aus dem PDF verwendet. Ist kein geeignetes XML vorhanden, wird der PDF-Text extrahiert und anschließend lokal über LM Studio analysiert.

```text
PDF
 |
 +-- Factur-X/ZUGFeRD XML vorhanden --> strukturierte Auswertung
 |
 +-- kein XML ------------------------> PDF-Text -> LM Studio
                                                   |
                                                   v
                                             Dolibarr-Vorschau
                                                   |
                                                   v
                                            manueller Import
```

Damit ist keine externe Cloud-KI zwingend erforderlich.

## Voraussetzungen

Die aktuelle Entwicklungsfassung ist für eine Linux-/Dolibarr-Installation ausgelegt.

Benötigt werden:

- Dolibarr mit aktivierbarem Modul **REP Rechnungsimport**
- Python 3
- Python-Modul `requests`
- `pdftotext` aus `poppler-utils`
- für den KI-Fallback: LM Studio mit einem kompatiblen lokalen Modell

Der mitgelieferte Installer installiert die benötigten Linux-Pakete, soweit sie über die Paketverwaltung verfügbar sind.

## Installation

Das Paket enthält:

```text
install_repimport.sh
```

Auf dem Dolibarr-System:

```bash
chmod +x install_repimport.sh
sudo ./install_repimport.sh
```

Alternativ kann das Dolibarr-Verzeichnis angegeben werden:

```bash
sudo ./install_repimport.sh /pfad/zu/dolibarr
```

Das Modul wird unter `htdocs/custom/repimport/` bereitgestellt.

Der Parser befindet sich direkt im Modul:

```text
htdocs/custom/repimport/rechnungs_ki_split_v20_7.py
```

Dadurch gehören Modul und Parser immer zum gleichen Projektstand.

## Einrichtung in Dolibarr

Nach der Installation:

1. Dolibarr öffnen.
2. Zu den Modulen/Anwendungen wechseln.
3. **REP Rechnungsimport** aktivieren.
4. Die REP-Import-Einstellungen öffnen.
5. Importverzeichnis konfigurieren.
6. LM-Studio-URL konfigurieren.
7. LM-Studio-Modell eintragen.
8. Timeout nach Bedarf einstellen.
9. bei Bedarf Sammellieferanten konfigurieren.

Ein bereits vorhandener Lieferant wird möglichst automatisch erkannt. Ein neuer Lieferant wird nicht ohne Bestätigung des Anwenders angelegt.

## LM Studio

Für den KI-Fallback muss LM Studio auf einem Rechner im lokalen Netzwerk laufen und eine OpenAI-kompatible Chat-API bereitstellen.

Im REP-Import werden konfiguriert:

- **LM Studio URL**
- **LM Studio Modell**
- **LM Studio Timeout**

Beispiel:

```text
URL:
http://192.168.x.x:1234/v1/chat/completions

Modell:
gemma-4-e4b-it
```

Die IP-Adresse ist nur ein Beispiel und muss an die eigene Installation angepasst werden.

Das aktuell getestete Modell ist **Gemma 4 E4B IT**. Andere Modelle können getestet werden, sind aber nicht automatisch gleich gut für die verwendeten Rechnungsformate geeignet.

## Sammellieferanten

Sammellieferanten können als Liste hinterlegt werden:

```text
Amazon
eBay
Zalando
```

Wird ein solcher Begriff im Rechnungsdokument erkannt, kann der konfigurierte Sammellieferant dem erkannten Rechnungsaussteller vorgezogen werden. Der tatsächlich erkannte Rechnungsaussteller bleibt als zusätzliche Information erhalten.

## Rechnungspositionen

Die einzelnen Artikelpositionen werden bewusst nicht automatisch als einzelne Dolibarr-Produkte angelegt.

Stattdessen wird eine einzige freie Rechnungszeile verwendet. Die Positionen werden in deren Beschreibung dokumentiert:

```text
REP-YYYYMM-NNNN
Pos. | Menge | Artikel-Nr. | Bezeichnung | Einzel | Gesamt

1 | 5 | 12345 | Artikelbeschreibung | 3,90 | 19,50
2 | 2 | 67890 | Weitere Beschreibung | 4,20 | 8,40
```

So bleibt die Originalstruktur der Lieferantenrechnung nachvollziehbar, ohne für jeden Lieferantenartikel zwingend einen neuen Dolibarr-Artikel anzulegen.

## Nebenkosten

Versand, Porto, Zahlungsartzuschläge und vergleichbare Nebenkosten sollen nicht fälschlich als Artikelposition importiert werden.

Explizit auf der Rechnung ausgewiesene zusätzliche Kosten und Summen können für die Rechnungsprüfung berücksichtigt werden.

## Batchimport

Das konfigurierte Importverzeichnis kann nach neuen PDF-Rechnungen durchsucht werden.

Die Verarbeitung erfolgt nacheinander. Bereits analysierte Dateien werden anhand ihrer Dateiinformationen erkannt und nicht erneut analysiert.

Die Ergebnisse können anschließend einzeln geprüft und importiert werden.

Fehlerhafte oder abgebrochene Analysen können gezielt gelöscht und anschließend erneut verarbeitet werden.

## Datenschutz

Der KI-Fallback ist für eine lokale Verarbeitung vorgesehen. Rechnungsdaten müssen dadurch nicht an einen externen KI-Anbieter übertragen werden.

Die tatsächliche Datenübertragung hängt von der individuellen LM-Studio-Konfiguration und dem Netzwerk des Anwenders ab.

## Sicherheit und Kontrolle

REP Rechnungsimport ist kein vollautomatischer Blindimport.

Die erkannte Rechnung wird zunächst zur Kontrolle bereitgestellt. Besonders bei neuen Lieferanten oder ungewöhnlichen Rechnungsformaten sollte der Anwender die Daten vor dem endgültigen Import prüfen.

## Entwicklungsstatus

**0.6.3 – Entwicklungs-/Beta-Version**

Die nächsten Entwicklungsstufen dienen insbesondere der Stabilisierung, Dokumentation, Kompatibilitätsprüfung und dem Praxistest mit unterschiedlichen Rechnungsformaten.

Geplante Entwicklung:

```text
0.6.x  Entwicklungsstand / Fehlerkorrekturen
0.7.x  Stabilisierung
0.8.x  Community-Testphase
0.9.x  Release Candidate
1.0.0  erste stabile Community-Version
```

Diese Einteilung kann sich während der Entwicklung noch ändern.

## Bekannte Einschränkungen

Die Qualität der automatischen Erkennung hängt vom Aufbau und Inhalt der jeweiligen Rechnung ab.

Bei stark unterschiedlichen PDF-Layouts, schlechten Scans, handschriftlichen Angaben oder ungewöhnlichen Tabellenstrukturen kann eine manuelle Korrektur erforderlich sein.

Die Unterstützung für weitere Rechnungsformate und Modelle wird im Verlauf der Entwicklung erweitert.

## Projektstruktur

```text
install_repimport.sh
repimport/
├── admin/
├── class/
├── core/
├── lang/
├── lib/
├── batch_worker.php
├── import.php
├── index.php
├── rechnungs_ki_split_v20_7.py
└── README.md
```

## Mitwirkung

Das Projekt soll nach der Stabilisierung als Open-Source-Projekt für die Dolibarr-Community bereitgestellt werden.

Fehlerberichte, Testrechnungen ohne schützenswerte personenbezogene oder geschäftliche Daten, Verbesserungsvorschläge und Beiträge zu weiteren Rechnungsformaten sind willkommen.

## Lizenz

Die endgültige Open-Source-Lizenz wird vor der ersten öffentlichen Community-Veröffentlichung festgelegt und im Repository ergänzt.

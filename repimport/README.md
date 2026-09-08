# REP Rechnungsimport

**REP Rechnungsimport** ist ein Open-Source-Modul für [Dolibarr](https://www.dolibarr.org/) zur automatisierten Erkennung und Übernahme von Lieferantenrechnungen.

Der aktuelle Stand ist **Version 0.6.3** und befindet sich weiterhin in Entwicklung. Eine Version 1.0 ist derzeit ausdrücklich nicht vorgesehen.

## Funktionen

REP Rechnungsimport verarbeitet PDF-Lieferantenrechnungen und übernimmt die erkannten Rechnungsdaten in Dolibarr.

Der grundlegende Ablauf:

1. PDF-Rechnung auswählen oder aus einem Importverzeichnis einlesen.
2. Prüfen, ob Factur-X-/ZUGFeRD-XML eingebettet ist.
3. Wenn XML vorhanden ist, diese strukturierten Daten bevorzugt verwenden.
4. Andernfalls den PDF-Text extrahieren und über eine lokal erreichbare LM-Studio-API analysieren.
5. Rechnungsdaten und Positionen erkennen.
6. Versandkosten, Zuschläge und sonstige Nebenkosten von den Artikelpositionen trennen.
7. Erkennung kontrollieren und Lieferantenzuordnung bestätigen bzw. korrigieren.
8. Lieferantenrechnung in Dolibarr anlegen.
9. Original-PDF archivieren und mit der Rechnung verknüpfen.

## Rechnungspositionen

REP Rechnungsimport legt die erkannten Artikel **nicht automatisch als einzelne Dolibarr-Produkte** an.

Die Positionen einer Lieferantenrechnung werden als **eine gemeinsame freie Textposition** in der Dolibarr-Rechnung abgelegt.

Dokumentiert werden dabei:

- Positionsnummer
- Menge
- Artikelnummer
- Artikelbezeichnung
- Einzelpreis
- Gesamtpreis

Dadurch bleibt die Lieferantenrechnung nachvollziehbar, ohne für jeden Fremdartikel einen eigenen Produktstammsatz in Dolibarr anlegen zu müssen.

## Factur-X / ZUGFeRD

Enthält ein PDF eingebettete Factur-X-/ZUGFeRD-XML-Daten, werden diese bevorzugt ausgewertet.

Dadurch können strukturierte Rechnungsdaten zuverlässiger übernommen werden und die KI-Erkennung wird vermieden, wenn bereits maschinenlesbare Daten vorhanden sind.

## KI-Auswertung über LM Studio

Bei normalen PDF-Rechnungen ohne nutzbare eingebettete XML-Daten kann REP Rechnungsimport eine lokal erreichbare **LM-Studio-API** verwenden.

Die Verbindung wird im Dolibarr-Modul konfiguriert:

- LM-Studio-URL
- Modellname
- Timeout

Der Parser ist Bestandteil des REP-Import-Moduls.

Der derzeit getestete Modellstand ist:

```text
gemma-4-e4b-it

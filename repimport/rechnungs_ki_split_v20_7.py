#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Rechnungsparser v20.7
Factur-X/ZUGFeRD first, otherwise pdftotext + LM Studio.
LM Studio URL, Modell und Timeout können per Kommandozeile vorgegeben werden.
"""

import argparse, json, re, subprocess, sys, tempfile
from pathlib import Path
import xml.etree.ElementTree as ET

try:
    import requests
except ImportError:
    requests = None

DEFAULT_URL = "http://192.168.178.32:1234/v1/chat/completions"
DEFAULT_MODEL = "gemma-4-e4b-it"

EXCLUDE_TERMS = (
    "versand","porto","fracht","transport","lieferkosten","liefergebühr",
    "liefergebuehr","zustell","zustellung","nachnahme","zahlungsart",
    "zuschlag","verpackungskosten","verpackung"
)

def lname(tag):
    return tag.rsplit("}", 1)[-1]

def first_desc(element, name):
    if element is None: return None
    for item in element.iter():
        if lname(item.tag) == name: return item
    return None

def text_of(element, name):
    item = first_desc(element, name)
    if item is None or item.text is None: return None
    value = item.text.strip()
    return value or None

def clean_dash(value):
    if value is None: return None
    value = str(value).strip()
    return None if value in ("", "-", "—", "–") else value

def number(value):
    if value is None: return None
    try:
        v = float(str(value).strip().replace(",", "."))
        return int(v) if v.is_integer() else v
    except Exception:
        return value

def date_102(value):
    if not value: return None
    value = value.strip()
    if len(value) == 8 and value.isdigit():
        return f"{value[6:8]}.{value[4:6]}.{value[:4]}"
    return value

def extract_embedded_xml(pdf):
    tmp = Path(tempfile.mkdtemp(prefix="facturx_"))
    try:
        p = subprocess.run(["pdfdetach","-saveall","-o",str(tmp),str(pdf)],
                           capture_output=True, text=True)
        if p.returncode != 0: return None
        xmls = list(tmp.glob("*.xml"))
        for x in xmls:
            if x.name.lower() == "factur-x.xml": return x
        return xmls[0] if xmls else None
    except (FileNotFoundError, OSError):
        return None

def is_excluded_position(description):
    s = (description or "").strip().lower()
    return bool(s) and any(term in s for term in EXCLUDE_TERMS)

def parse_facturx(xml_path):
    root = ET.parse(xml_path).getroot()
    result = {
        "quelle":"Factur-X/ZUGFeRD XML", "quelldatei":None,
        "lieferant":{"name":None,"adresse":None,"plz":None,"ort":None,
                     "land":None,"telefon":None,"email":None,
                     "webseite":None,"ust_id":None,"kundennummer":None},
        "rechnung":{"rechnungsnummer":None,"rechnungsdatum":None,
                    "bestellnummer":None,"faelligkeitsdatum":None,
                    "mehrwertsteuer_prozent":None},
        "positionen":[]
    }

    exchanged = first_desc(root, "ExchangedDocument")
    if exchanged is not None:
        result["rechnung"]["rechnungsnummer"] = clean_dash(text_of(exchanged,"ID"))
        issue = first_desc(exchanged,"IssueDateTime")
        dt = first_desc(issue,"DateTimeString") if issue is not None else None
        if dt is not None:
            result["rechnung"]["rechnungsdatum"] = date_102(dt.text)

    agreement = first_desc(root,"ApplicableHeaderTradeAgreement")
    if agreement is not None:
        seller = first_desc(agreement,"SellerTradeParty")
        if seller is not None:
            result["lieferant"]["name"] = clean_dash(text_of(seller,"Name"))
            address = first_desc(seller,"PostalTradeAddress")
            if address is not None:
                for key, tag in (("adresse","LineOne"),("plz","PostcodeCode"),
                                 ("ort","CityName"),("land","CountryID")):
                    result["lieferant"][key] = clean_dash(text_of(address,tag))
            contact = first_desc(seller,"DefinedTradeContact")
            if contact is not None:
                phone = first_desc(contact,"TelephoneUniversalCommunication")
                email = first_desc(contact,"EmailURIUniversalCommunication")
                if phone is not None:
                    result["lieferant"]["telefon"] = clean_dash(text_of(phone,"CompleteNumber"))
                if email is not None:
                    result["lieferant"]["email"] = clean_dash(text_of(email,"URIID"))
            uri = first_desc(seller,"URIUniversalCommunication")
            if uri is not None:
                v = clean_dash(text_of(uri,"URIID"))
                if v and "@" not in v and v.lower().startswith(("http://","https://","www.")):
                    result["lieferant"]["webseite"] = v
            for taxreg in seller.iter():
                if lname(taxreg.tag) == "SpecifiedTaxRegistration":
                    tid = first_desc(taxreg,"ID")
                    v = clean_dash(tid.text if tid is not None else None)
                    if v:
                        result["lieferant"]["ust_id"] = v
                        break

        buyer = first_desc(agreement,"BuyerTradeParty")
        if buyer is not None:
            result["lieferant"]["kundennummer"] = clean_dash(text_of(buyer,"ID"))

        order = first_desc(agreement,"SellerOrderReferencedDocument")
        if order is None:
            order = first_desc(agreement,"BuyerOrderReferencedDocument")
        if order is not None:
            result["rechnung"]["bestellnummer"] = clean_dash(text_of(order,"IssuerAssignedID"))

    terms = first_desc(root,"SpecifiedTradePaymentTerms")
    due = first_desc(terms,"DueDateDateTime") if terms is not None else None
    dt = first_desc(due,"DateTimeString") if due is not None else None
    if dt is not None:
        result["rechnung"]["faelligkeitsdatum"] = date_102(dt.text)

    vats = []
    for el in root.iter():
        if lname(el.tag) == "RateApplicablePercent" and el.text:
            try: vats.append(float(el.text.strip().replace(",",".")))
            except ValueError: pass
    unique = sorted(set(round(v,4) for v in vats))
    if len(unique) == 1:
        result["rechnung"]["mehrwertsteuer_prozent"] = unique[0]

    for line in [x for x in root.iter() if lname(x.tag)=="IncludedSupplyChainTradeLineItem"]:
        doc = first_desc(line,"AssociatedDocumentLineDocument")
        product = first_desc(line,"SpecifiedTradeProduct")
        delivery = first_desc(line,"SpecifiedLineTradeDelivery")
        agreement_line = first_desc(line,"SpecifiedLineTradeAgreement")
        settlement = first_desc(line,"SpecifiedLineTradeSettlement")

        desc = clean_dash(text_of(product,"Name"))
        if is_excluded_position(desc):
            print("  AUSGEFILTERT:", desc or "(ohne Bezeichnung)")
            continue

        article = clean_dash(text_of(product,"SellerAssignedID")) if product is not None else None
        if not article and product is not None:
            article = clean_dash(text_of(product,"GlobalID"))

        qty_el = first_desc(delivery,"BilledQuantity") if delivery is not None else None
        qty = number(qty_el.text) if qty_el is not None else None
        unit = clean_dash(qty_el.attrib.get("unitCode")) if qty_el is not None else None

        price = first_desc(agreement_line,"NetPriceProductTradePrice") if agreement_line is not None else None
        unit_price = number(text_of(price,"ChargeAmount")) if price is not None else None

        summ = first_desc(settlement,"SpecifiedTradeSettlementLineMonetarySummation") if settlement is not None else None
        total = number(text_of(summ,"LineTotalAmount")) if summ is not None else None

        result["positionen"].append({
            "positionsnummer":clean_dash(text_of(doc,"LineID")),
            "menge":qty, "einheit":unit,
            "artikelnummern":[article] if article else [],
            "artikelbezeichnung":desc,
            "einzelpreis":unit_price, "gesamtpreis":total
        })
    return result

def pdftotext(pdf):
    try:
        p = subprocess.run(["pdftotext","-layout",str(pdf),"-"],
                           capture_output=True,text=True,encoding="utf-8",errors="replace")
        if p.returncode != 0: raise RuntimeError(p.stderr.strip() or "pdftotext konnte die PDF nicht lesen.")
        return p.stdout
    except FileNotFoundError:
        raise RuntimeError("pdftotext wurde nicht gefunden.")

def extract_json(text):
    text = text.strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*","",text)
        text = re.sub(r"\s*```$","",text)
    a,b = text.find("{"),text.rfind("}")
    if a < 0 or b < a: raise ValueError("Keine JSON-Struktur in KI-Antwort gefunden.")
    return json.loads(text[a:b+1])

def ask_lmstudio(model, system_prompt, user_prompt, max_tokens, url=DEFAULT_URL, timeout=180):
    if requests is None:
        raise RuntimeError("Python-Modul 'requests' fehlt. Installieren mit: apt install python3-requests")
    payload={"model":model,"messages":[{"role":"system","content":system_prompt},
                                      {"role":"user","content":user_prompt}],
             "temperature":0,"max_tokens":max_tokens}
    print(f"LM Studio: {url}")
    print(f"Modell: {model}")
    try:
        r=requests.post(url,json=payload,timeout=timeout)
        r.raise_for_status()
        obj=r.json()
        choice=obj["choices"][0]
        content=choice["message"]["content"]
    except requests.RequestException as exc:
        raise RuntimeError(f"LM Studio nicht erreichbar: {exc}")
    except (ValueError,KeyError,IndexError,TypeError) as exc:
        raise RuntimeError(f"Ungültige Antwort von LM Studio: {exc}")
    print("\n--- KI-DIAGNOSE ---")
    print("Antwort-Zeichen:",len(content))
    print("finish_reason:",choice.get("finish_reason"))
    print("--- ENDE KI-DIAGNOSE ---\n")
    return content

def ai_header(text, model, url, timeout):
    system="""Du extrahierst Rechnungsdaten aus deutschem Rechnungstext.
Antworte ausschließlich mit gültigem JSON. Erfinde keine Werte.
Der Lieferant ist der Rechnungsaussteller, nicht der Kunde.
Betrachte den kompletten Text einschließlich Fußbereich.
Übernimm Straße, PLZ und Ort des Rechnungsausstellers.
Kundennummer nur übernehmen, wenn sie eindeutig als solche bezeichnet ist.
Rechnungsnummer und Bestellnummer nicht verwechseln."""
    user="""Ermittle:
lieferant: name, adresse, plz, ort, land, telefon, email, webseite, ust_id, kundennummer
rechnung: rechnungsnummer, rechnungsdatum, bestellnummer, faelligkeitsdatum, mehrwertsteuer_prozent

JSON:
{"lieferant":{"name":null,"adresse":null,"plz":null,"ort":null,"land":null,
"telefon":null,"email":null,"webseite":null,"ust_id":null,"kundennummer":null},
"rechnung":{"rechnungsnummer":null,"rechnungsdatum":null,"bestellnummer":null,
"faelligkeitsdatum":null,"mehrwertsteuer_prozent":null}}

RECHNUNGSTEXT:
"""+text
    return extract_json(ask_lmstudio(model,system,user,3000,url,timeout))

def ai_positions(text, model, url, timeout):
    system="""Du extrahierst alle echten Waren- und Leistungspositionen einer deutschen Rechnung.
Antworte ausschließlich mit gültigem JSON. Werte exakt übernehmen, nichts berechnen.
Eine Positionsnummer steht getrennt von der Menge und darf niemals mit ihr verbunden werden.
Beispiel: 1  1 Stk.  30001 ... => positionsnummer=1, menge=1.
Versand, Porto, Fracht, Transport, Lieferkosten, Zuschläge, Zahlungsartgebühren
und Verpackungskosten sind KEINE Positionen.
Arbeitsleistungen sind echte Positionen.
Mehrzeilige Beschreibungen zu einer Position zusammenfassen."""
    user="""JSON:
{"positionen":[{"positionsnummer":null,"menge":null,"einheit":null,
"artikelnummern":[],"artikelbezeichnung":null,"einzelpreis":null,"gesamtpreis":null}]}

RECHNUNGSTEXT:
"""+text
    data=extract_json(ask_lmstudio(model,system,user,8192,url,timeout))
    out=[]
    for pos in data.get("positionen",[]):
        if is_excluded_position(pos.get("artikelbezeichnung")):
            print("  AUSGEFILTERT:",pos.get("artikelbezeichnung") or "(ohne Bezeichnung)")
            continue
        p=pos.get("positionsnummer")
        if isinstance(p,str) and re.fullmatch(r"\d+",p.strip()):
            pos["positionsnummer"]=int(p.strip())
        out.append(pos)
    data["positionen"]=out
    return data

def enrich_supplier_from_text(result,text):
    s=result.setdefault("lieferant",{})
    name=str(s.get("name") or "").strip()
    lines=[re.sub(r"\s+"," ",x).strip() for x in text.splitlines()]
    if name:
        hits=[i for i,x in enumerate(lines) if name.lower() in x.lower()]
        if hits:
            block="\n".join(lines[hits[-1]:hits[-1]+8])
            if not s.get("email"):
                m=re.search(r"[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}",block,re.I)
                if m:s["email"]=m.group(0)
            if not s.get("telefon"):
                m=re.search(r"(?:Tel\.?|Telefon)\s*[:.]?\s*([+0-9][0-9() /.-]{5,})",block,re.I)
                if m:s["telefon"]=m.group(1).strip()
            if not s.get("webseite"):
                m=re.search(r"(?:https?://|www\.)[^\s]+",block,re.I)
                if m:s["webseite"]=m.group(0).rstrip(".,;")
    if not s.get("ust_id"):
        for pat in (r"(?:USt\.?[- ]?ID(?:Nr\.?|\s*Nr\.?)?|UStIDNr\?|Umsatzsteuer-Identifikationsnummer)\s*[:.]?\s*(DE\s*\d{9})",
                    r"\b(DE\d{9})\b"):
            m=re.search(pat,text,re.I)
            if m:
                s["ust_id"]=re.sub(r"\s+","",m.group(1)).upper(); break
    return result

def extract_vat_rate_from_text(text):
    # Deterministische Erkennung der Mehrwertsteuer aus dem PDF-Text.
    # Typische Schreibweisen: "Mehrwertsteuer 19,00 %", "MwSt. 19,00 %",
    # "USt. 19,00 %" sowie Varianten mit zusätzlichem Text.
    patterns = [
        r"(?:Mehrwertsteuer|Mehrwertsteuerbetrag|MwSt\.?|USt\.?|Umsatzsteuer|VAT)"
        r"[^\n]{0,120}?([0-9]{1,2}(?:[.,][0-9]{1,2})?)\s*%",
        r"([0-9]{1,2}(?:[.,][0-9]{1,2})?)\s*%[^\n]{0,80}"
        r"(?:Mehrwertsteuer|Mehrwertsteuerbetrag|MwSt\.?|USt\.?|Umsatzsteuer|VAT)",
    ]

    for pattern in patterns:
        for match in re.finditer(pattern, text, re.IGNORECASE):
            try:
                value = float(match.group(1).replace(",", "."))
                if 0 < value <= 100:
                    return value
            except ValueError:
                pass

    # Fallback für PDF-Layouts, bei denen Bezeichnung und Prozentwert
    # nur durch große Abstände getrennt sind.
    for line in text.splitlines():
        if re.search(
            r"Mehrwertsteuer|Mehrwertsteuerbetrag|MwSt\.?|USt\.?|Umsatzsteuer|VAT",
            line,
            re.IGNORECASE,
        ):
            for raw in re.findall(r"([0-9]{1,2}(?:[.,][0-9]{1,2})?)\s*%", line):
                try:
                    value = float(raw.replace(",", "."))
                    if 0 < value <= 100:
                        return value
                except ValueError:
                    pass

    return None



def extract_additional_costs(text):
    """
    Ermittelt ausdrücklich ausgewiesene Zusatzkosten, die keine Waren-/Leistungsposition
    sind, z.B. Porto, Versand, Fracht oder Lieferkosten. Es wird nichts aus Positionen
    herausgerechnet und nichts berechnet.
    """
    terms = (
        r"porto", r"versand(?:kosten)?", r"fracht(?:kosten)?",
        r"transport(?:kosten)?", r"lieferkosten", r"liefergeb(?:ühr|uehr)",
        r"zustell(?:ung|kosten)?", r"verpackungskosten"
    )
    term_re = "|".join(terms)
    amount_re = r"([0-9]{1,6}(?:[.,][0-9]{1,2})?)\s*(?:€|EUR)?"

    found = []
    seen = set()

    for raw_line in text.splitlines():
        line = re.sub(r"\s+", " ", raw_line).strip()
        if not line:
            continue
        mterm = re.search(term_re, line, re.IGNORECASE)
        if not mterm:
            continue

        # Beträge müssen eindeutig als Geldbetrag erkennbar sein.
        # Wichtig: Keine nackten Ganzzahlen akzeptieren, denn in Fußzeilen
        # stehen häufig USt-IDs, Telefonnummern oder andere Nummern.
        # Zulässig sind daher:
        #   - Dezimalbeträge wie 5,95 oder 12.50
        #   - Ganzzahlen nur mit explizitem € / EUR
        money_re = (
            r"(?<![A-Za-z0-9])"
            r"([0-9]{1,6}(?:[.,][0-9]{1,2})?)"
            r"\s*(€|EUR)?"
        )

        def find_money(fragment):
            matches = []
            for am in re.finditer(money_re, fragment, re.IGNORECASE):
                raw = am.group(1)
                currency = am.group(2)
                has_decimal = bool(re.search(r"[.,][0-9]{1,2}$", raw))
                # Ganzzahl ohne Währung ist nicht sicher genug (z.B. USt-ID).
                if not has_decimal and not currency:
                    continue
                try:
                    value = float(raw.replace(",", "."))
                except ValueError:
                    continue
                if value <= 0 or value > 100000:
                    continue
                matches.append((am, value))
            return matches

        # Prefer an amount on the same line after the keyword, e.g.
        # "zzgl. Porto 5,95".
        amounts = find_money(line[mterm.end():])
        if not amounts:
            amounts = find_money(line[:mterm.start()])

        for am, value in amounts:
            label = line
            key = (label.lower(), round(value, 2))
            if key in seen:
                continue
            seen.add(key)
            found.append({
                "bezeichnung": label,
                "betrag": round(value, 2)
            })
            break

    return found

def extract_invoice_totals(text):
    """
    Liest ausdrücklich ausgewiesene Rechnungssummen aus dem PDF-Text.
    Keine Summen werden berechnet.
    """
    result = {}

    # Beispiel: "19,00 % von 27,94"
    m = re.search(
        r"\b[0-9]{1,2}(?:[.,][0-9]{1,2})?\s*%\s+von\s+"
        r"([0-9]{1,9}(?:[.,][0-9]{2})?)",
        text,
        re.IGNORECASE,
    )
    if m:
        try:
            result["steuerbemessungsgrundlage"] = round(float(m.group(1).replace(",", ".")), 2)
        except ValueError:
            pass

    patterns = [
        ("bruttobetrag", r"(?:Bruttobetrag|Rechnungsbetrag|Gesamtbetrag|Endsumme)\s*(?:EUR|€)?\s*([0-9]{1,9}(?:[.,][0-9]{2})?)"),
        ("nettobetrag", r"(?:Nettobetrag|Nettosumme|Netto)\s*(?:EUR|€)?\s*([0-9]{1,9}(?:[.,][0-9]{2})?)"),
    ]
    for key, pattern in patterns:
        matches = list(re.finditer(pattern, text, re.IGNORECASE))
        if matches:
            raw = matches[-1].group(1)
            try:
                result[key] = round(float(raw.replace(",", ".")), 2)
            except ValueError:
                pass

    return result


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("pdf")
    ap.add_argument("--model",default=DEFAULT_MODEL)
    ap.add_argument("--url",default=DEFAULT_URL)
    ap.add_argument("--timeout",type=int,default=180)
    args=ap.parse_args()
    pdf=Path(args.pdf).expanduser().resolve()
    if not pdf.exists():
        print("FEHLER: Datei nicht gefunden:",pdf); sys.exit(1)
    print("REP Import Parser v20.7 – PDF:",pdf)
    print("Prüfe auf eingebettete Factur-X/ZUGFeRD-XML ...")
    xml=extract_embedded_xml(pdf)
    if xml is not None:
        print("  OK:",xml.name,"gefunden.")
        print("Strukturierte XML wird verwendet; KI wird übersprungen.")
        try:
            result=parse_facturx(xml); result["quelldatei"]=str(pdf)
            try:
                result["_repimport_dokumenttext"] = pdftotext(pdf)
            except Exception:
                result["_repimport_dokumenttext"] = ""
        except Exception as exc:
            print("FEHLER beim Lesen der Factur-X-XML:",exc); sys.exit(1)
    else:
        print("Keine eingebettete XML gefunden.")
        print("PDF wird mit pdftotext ausgelesen ...")
        try: text=pdftotext(pdf)
        except Exception as exc:
            print("FEHLER:",exc); sys.exit(1)
        print(f"  OK: {len(text)} Zeichen Rechnungstext gelesen.")
        print("1/3 Rechnungskopf wird erkannt ...")
        try: header=ai_header(text,args.model,args.url,args.timeout)
        except Exception as exc:
            print("FEHLER bei Rechnungskopf:",exc); sys.exit(1)
        print("2/3 Rechnungspositionen werden erkannt ...")
        try: positions=ai_positions(text,args.model,args.url,args.timeout)
        except Exception as exc:
            print("FEHLER bei Rechnungspositionen:",exc); sys.exit(1)
        result={"quelle":"PDF + KI","quelldatei":str(pdf),
                "_repimport_dokumenttext":text,
                "lieferant":header.get("lieferant",{}),
                "rechnung":header.get("rechnung",{}),
                "positionen":positions.get("positionen",[])}
        result=enrich_supplier_from_text(result,text)
        vat=extract_vat_rate_from_text(text)
        if vat is not None:
            result["rechnung"]["mehrwertsteuer_prozent"]=vat
            print(f"  Mehrwertsteuer aus PDF erkannt: {vat:.2f} %")

        zusatzkosten = extract_additional_costs(text)
        if zusatzkosten:
            result["zusatzkosten"] = zusatzkosten
            print("  Zusatzkosten außerhalb der Positionen erkannt:")
            for z in zusatzkosten:
                print(f"    - {z['bezeichnung']}: {z['betrag']:.2f}")

        totals = extract_invoice_totals(text)
        if totals:
            result["rechnungsummen"] = totals

        print("3/3 Ergebnis wird durch Python geprüft ...")
        errors=[]
        for i,p in enumerate(result["positionen"],1):
            if not p.get("artikelbezeichnung"): errors.append(f"Position {i}: Artikelbezeichnung fehlt.")
            if p.get("menge") is None: errors.append(f"Position {i}: Menge fehlt.")
            if p.get("gesamtpreis") is None: errors.append(f"Position {i}: Gesamtpreis fehlt.")
        if errors:
            print("--- PRÜFUNG: FEHLER ---")
            for e in errors: print("  -",e)
        else: print("  OK: Positionen vollständig geprüft.")
    output=pdf.with_name(pdf.stem+"_ergebnis.json")
    output.write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding="utf-8")
    print("\n=== ERGEBNIS ===")
    print(json.dumps(result,ensure_ascii=False,indent=2))
    print("\nJSON gespeichert:",output)

if __name__=="__main__":
    main()

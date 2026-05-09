# Invoice-api.xhub für Shopware

Kostenloser, MIT-lizenzierter Konnektor zwischen Shopware 6.6/6.7 und der E-Invoicing-API [invoice-api.xhub.io](https://invoice-api.xhub.io). Erzeugt EU-konforme elektronische Rechnungen (PDF, XRechnung, ZUGFeRD) direkt aus Shopware-Bestellungen.

> English documentation: [README.md](./README.md)

## Funktionen

- Automatische Rechnungserstellung bei konfigurierbaren Bestellstatus-Übergängen
- 3 Formate live: **PDF** (14 Länder), **XRechnung** (DE), **ZUGFeRD** (DE/AT)
- Roadmap Q3 2026: Factur-X, FatturaPA, Facturae, ebInterface, UBL, ISDOC, NAV
- Atomare, lückenlose Rechnungsnummerierung (§14 UStG-konform)
- Rückerstattung löst automatisch eine Gutschrift aus
- DSGVO- / CCPA-Hooks
- Vue.js-Admin-Modul: Neu erstellen, Herunterladen, Historie pro Bestellung
- Asynchron via Symfony Messenger — kein blockierender Checkout

## Installation

### Per Composer (empfohlen)

```bash
composer require xhubio/shopware-invoice-api-xhub
bin/console plugin:refresh
bin/console plugin:install --activate InvoiceApiXhub
bin/console cache:clear
```

### Manuelle ZIP-Installation

1. Laden Sie die aktuelle `InvoiceApiXhub-*.zip` aus den [GitHub Releases](https://github.com/xhubio/shopware-invoice-api-xhub/releases) herunter.
2. Entpacken Sie das Archiv nach `custom/plugins/InvoiceApiXhub` in Ihrer Shopware-Installation.
3. Führen Sie `bin/console plugin:refresh && bin/console plugin:install --activate InvoiceApiXhub` aus.
4. Leeren Sie den Cache: `bin/console cache:clear`.

## Konfiguration

1. Registrieren Sie sich auf [invoice-api.xhub.io](https://invoice-api.xhub.io) und erzeugen Sie einen API-Schlüssel in der [Console](https://console.invoice-api.xhub.io/api-keys).
2. Im Shopware-Admin: **Erweiterungen -> Meine Erweiterungen -> "Invoice-api.xhub für Shopware" -> "..."-Menü -> Konfigurieren**.
3. Tragen Sie folgende Werte ein:
   - **API-Verbindung**: API-Schlüssel + Basis-URL (Standard: `https://service.invoice-api.xhub.io`)
   - **Dokument-Voreinstellungen**: Land, Format, Trigger (wann auslösen), Schalter "Per E-Mail anhängen", Zahlungsziel in Tagen
   - **Rechnungsnummerierung**: Format (z. B. `2026-{seq:0000}` für §14 UStG), Reset-Modus
   - **Verkäufer**: Ihre Firmendaten + Steuernummer/USt-IdNr. + Bankverbindung (IBAN/BIC für einige Länderprofile zwingend)
   - **Länderspezifisch (DE)**: Standard-Leitweg-ID für B2G-XRechnung
4. Speichern.

## So testen Sie das Plugin (Walkthrough für den Reviewer)

Dieser Abschnitt führt den Shopware-Marketplace-Reviewer durch einen vollständigen Test des Plugins. Er funktioniert mit einer Shopware-Installation, in der das Plugin gemäß dem vorigen Abschnitt installiert und konfiguriert wurde.

**Voraussetzungen:**

- Plugin installiert und aktiviert
- API-Schlüssel hinterlegt (ein Sandbox-Schlüssel für Review-Zwecke ist verfügbar — siehe [Hinweis für Reviewer](#hinweis-für-reviewer) unten)
- Eine Testbestellung mit mindestens einer Produkt-Position

**Test 1 — Automatische Erstellung beim Abschluss einer Bestellung**

1. Öffnen Sie Admin -> Bestellungen -> wählen Sie eine Testbestellung mit Status `Offen`.
2. Setzen Sie die Bestellung auf `Abgeschlossen` (Bestellstatus -> "Abgeschlossen").
3. Warten Sie ca. 5 Sekunden (der Symfony-Messenger-Sync-Transport verarbeitet die Aufgabe innerhalb des Requests).
4. Aktualisieren Sie die Bestelldetailseite -> scrollen Sie ans Ende des Tabs **Allgemein**.
5. **Erwartet:** Eine neue Karte "Invoice-api.xhub" erscheint mit:
   - Unterkarte "Rechnungs-Aktionen" mit `Dateiname: INV-{Bestellnummer}.pdf` und `Erzeugt am: <ISO-Zeitstempel>`
   - Unterkarte "Historie" mit einem Eintrag mit Status `success`

**Test 2 — Neu erstellen**

1. Klicken Sie in derselben Bestellung auf den Button "Rechnung neu erstellen".
2. **Erwartet:** Dateiname und Erzeugt-am werden auf den aktuellen Zeitstempel aktualisiert; in der Historie erscheint ein weiterer `success`-Eintrag.

**Test 3 — Download**

1. Klicken Sie auf den Button "Herunterladen".
2. **Erwartet:** Der Browser lädt `INV-{Bestellnummer}.pdf` (~20-25 KB) herunter — eine gültige PDF/A-3-Datei.

**Test 4 — Format XRechnung**

1. Setzen Sie in der Konfiguration das Format auf `XRechnung (DE)`.
2. Erstellen Sie die Rechnung erneut.
3. **Erwartet:** Der Dateiname lautet `INV-{Bestellnummer}_xrechnung.xml`, der Inhalt ist gültiges UBL 2.1 mit BIS-3.0- / EN-16931-customizationID.

**Test 5 — Rückerstattung erzeugt Gutschrift (optional)**

1. Öffnen Sie die Bestellung -> erstatten Sie die Order-Transaktion (Admin -> Button "Rückerstattung").
2. **Erwartet:** Ein neues Generierungs-Event wird ausgelöst mit `type=credit_note` und negierten Beträgen.

### Hinweis für Reviewer

Für den Review-Prozess stellen wir einen Sandbox-API-Schlüssel mit dem Tag "review" bereit — bitte senden Sie eine E-Mail an `support@invoice-api.xhub.io` mit Ihrer Reviewer-ID, und wir senden Ihnen einen Schlüssel, der für die Dauer des Reviews gültig ist.

Das Plugin sendet ausgehende HTTPS-Requests an `https://service.invoice-api.xhub.io` zur Rechnungserstellung. Übermittelte Daten: Bestellpositionen, Rechnungs-/Lieferadresse, konfigurierte Verkäufer-Stammdaten. Es werden keine Daten gesendet, bevor der API-Schlüssel explizit konfiguriert wurde. Datenschutzerklärung: https://invoice-api.xhub.io/privacy

## Offenlegung externer Dienste

Dieses Plugin verbindet sich mit **invoice-api.xhub.io** (Betreiber: xhub.io) zur Rechnungserstellung. Das Plugin sendet per POST die Bestellpositionen, die Rechnungsadresse, die konfigurierten Verkäufer-Stammdaten und die Steuer-Aufschlüsselung an die API und erhält eine konforme Rechnungsdatei zurück. Es werden keine Daten gesendet, bevor der Nutzer einen API-Schlüssel konfiguriert hat, und nur dann, wenn der konfigurierte Bestellstatus-Trigger ausgelöst wird.

- Diensteanbieter: xhub.io
- AGB: https://invoice-api.xhub.io/terms
- Datenschutz: https://invoice-api.xhub.io/privacy
- Preise: https://console.invoice-api.xhub.io

## Geschäftsmodell

Das Plugin ist unter MIT-Lizenz **kostenlos** und wird über GitHub, packagist.org sowie (geplant) den Shopware Store verteilt. Die Wertschöpfung liegt im separaten Abonnement der Kundin/des Kunden bei invoice-api.xhub.io. Es handelt sich um dasselbe SaaS-Companion-Modell, das auch [Stripe](https://store.shopware.com/en/sw5stri93537005054.html), [Mollie](https://store.shopware.com/en/sw5moll170498000150.html) und [PayPal](https://store.shopware.com/en/swag257690075008/paypal-payments.html) auf Shopware verwenden. Es gibt keine Pro-Stufe innerhalb des Plugins und keine Lizenzschlüssel-Prüfung.

## Kompatibilität

- Shopware 6.6
- Shopware 6.7
- PHP 8.1+

## Entwicklung

Siehe `docs/SETUP-WALKTHROUGH-DOCKER.md` für einen Docker-basierten Dev-/Test-Stack mit nur einem Befehl.

## Support

- Fehlerberichte / Feature-Wünsche: https://github.com/xhubio/shopware-invoice-api-xhub/issues
- E-Mail: support@invoice-api.xhub.io
- Dokumentation: https://invoice-api.xhub.io/de/docs/integrations/shopware

## Lizenz

MIT — siehe [LICENSE.md](./LICENSE.md)

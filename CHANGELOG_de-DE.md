# 1.0.0

* Erstveröffentlichung.
* Aktuell verfügbare Formate: PDF (alle 14 unterstützten Länder), XRechnung (DE, BIS 3.0 / EN 16931), ZUGFeRD (DE/AT, Version 2.3 / 2.4, hybride PDF/A-3 mit eingebetteter XML).
* Automatische Erzeugung bei konfigurierbaren Bestellstatus-Übergängen (offen / in Wartestellung / in Bearbeitung / abgeschlossen).
* Atomare, lückenlose Rechnungsnummerierung über eine eigene DB-Tabelle — §14 UStG-konform bei Verwendung des Token-Formats `{seq:0000}`.
* Automatische Erzeugung von Gutschriften/Stornorechnungen beim Statusübergang `order_transaction.refunded`.
* Individuelle Rechnungsvorlagen über Template-ID (UUID), global oder pro Bestellung konfigurierbar.
* DSGVO-/CCPA-Hooks über das Event `customer.deleted` — Rechnungsdaten und gespeicherte Dateien werden bei Kunden-Löschung (Recht auf Vergessenwerden) entfernt.
* Vue.js-Administrationsmodul: Neu generieren, Herunterladen sowie Historie-Cards im Reiter „Allgemein" der Bestelldetailansicht.
* Asynchrone Erzeugung über Symfony Messenger — Checkout wird nicht blockiert, optional vollständig entkoppelt über `messenger:consume async` Worker.

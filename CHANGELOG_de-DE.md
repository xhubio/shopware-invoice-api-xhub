# 1.1.0

* **Auto-Email-Anhang** — generierte Rechnung wird automatisch an die Kunden-Bestellbestätigungs-Mail angehängt via `MailBeforeValidateEvent` (Config-Toggle `attachToEmail`, Default an).
* **Storefront-Customer-Portal** — Kunden können ihre Rechnung aus dem Account-Bereich (`/account/invoice/{orderId}`) herunterladen, mit Eigentums-Prüfung (`customerId === order.orderCustomer.customerId`). 403 bei fremder Order, 404 wenn keine Rechnung erzeugt.
* **VIES-USt-ID-Validierung** — optionale Pre-Validierung der Käufer-USt-ID gegen die EU-VIES-API vor jeder Generierung. Bei ungültiger USt-ID wird die Generierung übersprungen und im Audit-Log dokumentiert. Toggle `validateVatBeforeGenerate` in der Plugin-Konfiguration, Default aus.
* **Audit-Trail-Entity** — neue Tabelle `invoice_api_xhub_audit` ersetzt die synthetische Custom-Field-Historie. Per-Step-Events mit Status/Format/Latenz/Fehler/User-ID, abfragbar aus der Admin-Verlauf-Card.
* **PHP-8.1-Backed-Enums** — `InvoiceFormat`, `Trigger`, `InvoiceType` für typsichere interne Repräsentation. `config.xml` behält die String-Werte für Kompatibilität mit dem Shopware-Config-System.
* **Sales-Channel-spezifische Config-Overrides** — `SystemConfigService::get()` erhält jetzt die `salesChannelId` der Bestellung. Multi-Storefront-Shops können pro Sales-Channel anderes Land/Format/Seller-Profil konfigurieren; Shopware merged Channel-Overrides über die globalen Defaults.
* **Migration `1715000001CreateAuditTable.php`** — legt die Audit-Tabelle mit FK-Cascade-on-Order-Delete an; bei Uninstall mit `keepUserData=false` wird die Tabelle wieder gedroppt.

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

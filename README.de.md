# Paymos für Blesta

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles Non-Merchant-Zahlungsgateway und zugehöriges Abgleichs-Plugin von Paymos für **Blesta v5 und v6**.

Akzeptieren Sie vier Stablecoins (**USDT**, **USDC**, **USD1**, **DAI**) sowie das goldgedeckte **XAUT** über den gehosteten Paymos-Checkout — mit 1-Klick **Paymos verbinden**, AES-256-GCM-verschlüsselter Speicherung von Zugangsdaten, absturzsicherer Rechnungserstellung mit Positionen (`products[]`), signierten Multi-Environment-Webhooks, Rückwärtsprüfung, geplantem 24-Stunden-Abgleich für Rechnungen und Erstattungen sowie serverseitig berechneten `full`- und `overpayment`-Erstattungen.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- Blesta v5 oder v6
- PHP 8.1+ mit den Erweiterungen `curl`, `hash`, `json`, `openssl` und `pdo`
- Eine **HTTPS**-Installations-URL für Blesta (unverschlüsseltes HTTP wird von „Paymos verbinden“ abgelehnt)
- Ein Paymos-Konto mit ausgewähltem Zielprojekt im Paymos-Dashboard

Bei der Installation ist kein Composer-Schritt nötig: `paymos/php-sdk` ist im Release-Archiv direkt unter `components/gateways/nonmerchant/paymos/vendor/paymos/php-sdk/` enthalten.

## Installation und Verbindung

1. Laden Sie das aktuelle Release-Paket von [GitHub Releases](https://github.com/Paymos-labs/blesta/releases/latest) herunter.
2. Entpacken Sie das Archiv in das Web-Stammverzeichnis von Blesta. Es installiert zwei Komponenten:
   - `components/gateways/nonmerchant/paymos/` — das Non-Merchant-Zahlungsgateway von Paymos;
   - `plugins/paymos/` — das Begleit-Plugin für Blesta mit 10-Minuten-Cron-Abgleich (`Reconciler` + `RefundAdapter::run()`) sowie CSRF-geschützten Staff-Controllern (`AdminConnect`, `AdminRefunds`).
3. Installieren Sie im Blesta-Adminbereich beide Komponenten:
   - **Settings → Company → Payment Gateways → Available → Paymos → Install**
   - **Settings → Company → Plugins → Available → Paymos Companion → Install**
4. Öffnen Sie im Paymos-Dashboard das Projekt, über das Sie abrechnen möchten.
5. Öffnen Sie in Blesta **Settings → Company → Payment Gateways → Paymos → Manage** und klicken Sie auf **Paymos verbinden** (Connect Paymos).
6. Prüfen Sie Installations-URL sowie Benutzercode und bestätigen Sie die Verbindung im Paymos-Tab.

Diese eine Bestätigung richtet die Payment-API-Zugangsdaten sowohl für **Sandbox** als auch für **Live** ein (`pk_*` / `sk_*` / `prj_*` / `whsec_*`, einschließlich der Erstattungsberechtigung `invoice_refunds_v1`) und registriert den Webhook-Callback automatisch:

```text
https://your-blesta-domain/callback/gw/1/paymos/
```

### Hinweis zu Multi-Company-Installationen

One-Click Connect bindet den kanonischen Callback-Pfad `/callback/gw/1/paymos/` für die Hauptfirma von Blesta (`company_id = 1`). Multi-Company-Installationen unter `company_id != 1` werden durch One-Click Connect nicht eingerichtet.

## Einstellungen & Speicherung von Geheimnissen

Nach dem Verbinden zeigt die Gateway-Einstellungsseite ausschließlich nicht-geheime Optionen:

- **Verbindung (Connection):** schreibgeschützter Status (`Connected` / `Not connected`) und die Schaltfläche **Paymos verbinden** / **Paymos neu verbinden**.
- **Modus (Mode):** `Sandbox (Test)` oder `Live (Mainnet)`.
- **Buttontext (Button Text):** Beschriftung auf der Zahlungsschaltfläche der Blesta-Rechnung.
- **Webhook-Callback-URL:** schreibgeschützter Hinweis mit `/callback/gw/1/paymos/`.

Weder in der Gateway-Oberfläche noch in den Metadaten gibt es manuelle Eingabefelder für API Key, API Secret, Project ID oder Webhook Secret. Alle Zugangsdaten und der temporäre RFC-8628-Gerätestatus werden in einem authentifizierten `AES-256-GCM`-Umschlag (`AesGcmEnvelope`) versiegelt, per HKDF aus `Configure::get('Blesta.system_key')` abgeleitet, in `paymos_settings` abgelegt und niemals in HTML oder Logs ausgegeben.

## Zahlungsablauf, Zuverlässigkeit & Cron-Abgleich

- **Absturzsicherer Checkout & `products[]`:** Vor dem Versand von `POST /v1/invoices` speichert `CheckoutService` eine `STATUS_CREATING`-Zeile. Wird der Prozess vor dem Speichern der Antwort unterbrochen, wiederholt der nächste Aufruf exakt dieselben Werte für `external_order_id`, `project_id`, `amount`, `currency` und `environment`, ohne ein Duplikat zu erzeugen. Bis zu 50 gültige Blesta-Rechnungspositionen werden in `products[]` übermittelt (bei fehlenden oder ungültigen Zeilen sauberer Fallback auf reine Betragsabrechnung). Weder `merchant_id` noch eine clientseitige TTL werden jemals gesendet.
- **Fail-closed-Rechnungsersetzung:** Ändern sich Betrag, Währung, Modus oder Projekt — oder endet eine vorherige Rechnung unbezahlt (`InvoiceRenewal`) — schließt `CheckoutService` die frühere Rechnung über `InvoiceReplacement::close()`, bevor eine neue angelegt wird. Ist die alte Rechnung noch offen, bereits bezahlt, als `paid_late` markiert, nicht erreichbar oder liefert sie 404, wird die Ersetzung blockiert (`InvoiceReplacementBlockedException`) und zur manuellen Prüfung vorgemerkt.
- **Signierte Multi-Environment-Webhooks:** `CallbackProcessor` prüft `X-Webhook-Signature` unabhängig von Groß-/Kleinschreibung über Sandbox und Live hinweg via `MultiEnvironmentWebhookVerifier` (Dual-`v1=`-Rotationsfrist, 300 Sekunden Taktabweichungstoleranz, Ablehnung falls beide Umgebungen passen). Owner-fenced atomare Deduplizierung liefert `200` für bereits verbuchte Duplikate, `409` für parallele laufende Zustellungen und `401` für ungültige oder veraltete Signaturen. Abschlussereignisse durchlaufen `InvoiceReverseVerifier` (`GET /v1/invoices/{id}`), Rollback-Schutz und `AmountGuard` (`HTTP 400` + `manual_review` bei Abweichung). `invoice.paid_late` wird als Notiz mit `paid_late_noted`-Markierung gespeichert, ohne den Zahlungsstatus zu ändern.
- **Geplantes Cron-Sicherheitsnetz:** Der 10-Minuten-Cron-Task des Begleit-Plugins (`paymos_reconcile`) prüft über `InvoiceReconciler` bis zu 50 unbezahlte Paymos-Rechnungen der letzten 24 Stunden (gedrosselt auf 600 Sekunden) und gleicht offene sowie im Dashboard ausgelöste Erstattungen ab.

## Erstattungen (`full` & `overpayment`)

Paymos unterstützt serverseitig berechnete **Voll-** (`full`) und **Überzahlungs-**Erstattungen (`overpayment`, für `paid_over`-Rechnungen) als ausgehende Blockchain-Transaktionen strikt an die verifizierte `first_sender`-Adresse. Beliebige Teilbeträge und nicht verwaltete Empfängerfelder (`destination_address`, `network`, `token`, `crypto_amount`) werden vor jedem Paymos-API-Aufruf abgewiesen.

- **Admin-Panel „Paymos-Erstattungen“ (`/admin/plugin/paymos/admin_refunds/`):** Nur für Mitarbeiter zugängliche, CSRF-geschützte Oberfläche für `full`- und (bei `paid_over`-Rechnungen) `overpayment`-Erstattungen mit dauerhafter UUIDv4-`request_token`-Idempotenz, `RefundCoordinator`-Verifizierung (`GET /v1/refunds/{id}` + `GET /v1/invoices/{id}`) und `RefundDiscovery` für direkt im Paymos-Dashboard gestartete Erstattungen.
- **Nativer Blesta-Erstattungs-Hook (`Paymos::refund()`):** Übermittelt eine `full`-Erstattung über `RefundCoordinator`. Da Paymos-Erstattungen asynchron abgewickelt werden, gibt `Paymos::refund()` `null` samt Hinweis zurück, solange die Erstattung `pending`, `processing` oder `unknown` ist — es wird niemals vorzeitig eine Buchungsbelastung erzeugt. Sobald Paymos `completed` bestätigt (über `withdrawal.completed`-Webhook oder Cron-Abgleich), trägt `RefundAdapter::applyCompleted()` die Erstattung bei `full` einmalig über `Transactions::edit` ein oder speichert bei `overpayment` eine Überzahlungsnotiz und belässt die ursprüngliche Rechnung auf bezahlt.

## Unterstützte Sprachen

Fertige native Übersetzungen sind für alle 6 Blesta-Sprachen enthalten:
`en_us`, `ru_ru`, `de_de`, `es_es`, `tr_tr`, `zh_cn`.

## Links

- [Dokumentation](https://paymos.io/de/docs/cms-blesta)
- [Quellcode](https://github.com/Paymos-labs/blesta)
- [Änderungsprotokoll](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
- Lizenz: [MIT](LICENSE)

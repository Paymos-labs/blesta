# Changelog

All notable changes to the Paymos for Blesta gateway and companion plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.5] - 2026-11-28

- fix(blesta): cron task, staff permissions, partial payments, refunds

### Added
- Staff permissions **Paymos → Connect Paymos / Paymos Refunds** (`getPermissionGroups()`, `getPermissions()`), enforced by `requireLogin()` on admin pages and as JSON `403` on the Connect and refund endpoints. Before, every staff member could open these pages, so on upgrade every staff group of the company is granted them once (narrow access under **Staff → Groups**); until that grant succeeds the pages keep their earlier access.
- Companion plugin locales `ru_ru`, `de_de`, `es_es`, `tr_tr`, `zh_cn` (`plugins/paymos/language/`).

### Fixed
- **Cron task registration (`PaymosPlugin`):** the `paymos_reconcile` task is registered with `task_type`/`dir`/`is_lang` as `CronTasks::add()` documents (the Automation list showed raw language keys), add/run failures are logged, upgrade repairs older tasks, and uninstall removes this company's task run via `getTaskRunByKey()`.
- **Partial payments and "Other Payment Amount" (`Paymos::buildProcess()`, `CallbackProcessor`):** the amount due at checkout is stored, so a payment of part of an invoice, or with extra account credit, is recorded with Blesta's per-invoice amounts instead of being held for manual review.
- **One payment recorded once (`CallbackProcessor`, `SnapshotStore`, cron applier):** the webhook and the cron reconciler share a lock per Paymos invoice and re-read the snapshot under it; the cron applier skips a transaction Blesta already has, and a rejected `Transactions::add()` keeps the snapshot retryable instead of marking it paid. A redelivered payment event hands Blesta the same transaction again only while Blesta does not hold it (a failed delivery); a transaction Blesta knows is never overwritten.
- **"Already paid in Blesta" guard (`CallbackProcessor`):** non-payment events no longer write `paid` into the snapshot; a real Paymos payment for an invoice already closed another way is flagged for manual review.
- **`paid_late` and manual review are visible (`CallbackProcessor`):** each leaves one sticky client note (`Clients::addNote`) in the store language.
- **Checkout recovery (`CheckoutService`):** a create refused for good (4xx other than 408/429) is closed as `create_failed` and the next attempt sends the current amount with a new key; a passed local `expires_at` is re-read from Paymos instead of blocking the buyer; a paid Paymos invoice is never reused, so a second top-up or the rest of a partly paid invoice gets a new invoice, unless Blesta has not stored that payment yet (the buyer then gets the same checkout); a refused replay never overwrites a key another request bound meanwhile; a final invoice returned for a never-used key is not bound; a zero-price line item no longer drops `products[]`.
- **Snapshot status (`SnapshotStore::updateStatus()`):** a late non-final status no longer overwrites a recorded final status.
- **Refunds (`RefundAdapter`, `SnapshotStore`):** each Paymos payment of an invoice (part payments, a payment of several invoices) is refunded on its own and none blocks another; a credit-only payment explains that it is refunded in the Paymos Dashboard; the native Refund button can retry after a failed refund.
- **Admin URLs (`Config::adminUri()`, controllers):** Connect and refund URLs keep the Blesta web directory (subfolder installs, no mod_rewrite).
- **Admin texts (`AdminRefunds`, `AdminConnect`):** refunds page labels and admin errors are localized; error responses carry the text plus a `reason` code.

### Removed
- The `Appcontroller.structure` hook: `injectAdminHelpers()` is now an empty handler kept for 1.0.0 installs, and upgrade removes the event.

## [1.0.4] - 2026-11-27

- chore: rebuild canonical CMS package

## [1.0.3] - 2026-11-26

- fix(blesta): resolve refunded transaction lookup in post-apply snapshot (#B-6)
- docs(plugins): add 6-language READMEs (EN/zh-CN/ru/es/de/tr) and YouTube walkthrough previews across all 25 Paymos-labs repos
- chore: bundle Paymos PHP SDK v1.13.0

## [1.0.2] - 2026-11-25

- fix(blesta): resolve plugin default view under Blesta 6.0 Paradigm admin theme

## [1.0.1] - 2026-11-25

- fix(plugins): accept stamped SemVer and avoid PHP 8.5 setAccessible deprecation in plugin tests
- fix(plugins): resolve 13 live CMS runtime issues in FOSSBilling 0.8.9, Paymenter v1.5.9, and Blesta 6.0.5
- chore: rebuild canonical CMS package

## [1.0.0] - 2026-11-25

### Added
- One-Click **Connect Paymos** RFC 8628 device bootstrap (`ConnectHandler`, `AdminConnect`) binding `plugin = "blesta"` and HTTPS installation URLs, atomically provisioning both Sandbox and Live credentials with `invoice_refunds_v1`.
- Authenticated `AES-256-GCM` credential and device-state storage (`CredentialStore`) keyed from `Configure::get('Blesta.system_key')` via `Paymos\Plugin\AesGcmEnvelope` and `CredentialSet`.
- Crash-safe checkout service (`CheckoutService`) persisting `STATUS_CREATING` intents before `POST /v1/invoices`, `recoverPendingIntent()`, `paid_late_noted` guard, fail-closed `InvoiceReplacement::close()`, `InvoiceRenewal`, and 1–50 Blesta line items in `products[]`.
- Multi-environment webhook processor (`CallbackProcessor`) using `MultiEnvironmentWebhookVerifier` (Sandbox + Live, dual-`v1=` rotation, 300s skew, `200`/`409`/`401`/`400`), `withdrawal.*` refund routing, `InvoiceReverseVerifier`, `AmountGuard`, rollback guard, and note-only `invoice.paid_late`.
- Companion Blesta plugin (`plugins/paymos/`) with 10-minute cron reconciliation (`Reconciler` 24h unpaid sweep with 600s throttle + `RefundAdapter::run()`), `Appcontroller.structure` hook, and staff+CSRF-protected `AdminConnect` and `AdminRefunds` controllers.
- Full asynchronous refund lifecycle (`RefundIntentStore` implementing `RefundIntentStoreInterface` + `RefundCandidateStoreInterface`, `RefundAdapter` implementing `NativeRefundProjectorInterface` with `RefundCoordinator`, `RefundDiscovery`, and `RefundCopy`) supporting both `full` and `overpayment` refunds with zero premature debit in `Paymos::refund()`.
- Six native locales (`en_us`, `ru_ru`, `de_de`, `es_es`, `tr_tr`, `zh_cn`) with full key parity across Connect, Checkout, Errors, and Refunds.
- Canonical ISO 4217 currency manifest in `config.json`, bundled `paymos/php-sdk >= 1.11.0`, and standalone PHP 8.1+ conformance test suite.

### Removed
- Legacy manual API Key, API Secret, Project ID, Webhook Secret, and API Base URL configuration fields.
- Legacy standalone MVP `GatewayService.php`, `scripts/package.py`, and prebuilt `dist/paymos-blesta-v1.0.0.zip`.

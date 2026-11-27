# Changelog

All notable changes to the Paymos for Blesta gateway and companion plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

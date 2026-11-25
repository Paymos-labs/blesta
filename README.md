# Paymos for Blesta

Official Paymos non-merchant payment gateway and companion reconciliation plugin for **Blesta v5 and v6**.

Accept four stablecoins (**USDT**, **USDC**, **USD1**, **DAI**) and gold-backed **XAUT** through Paymos hosted checkout, with One-Click **Connect Paymos**, AES-256-GCM encrypted credential storage, crash-safe invoice creation with line items (`products[]`), signed multi-environment webhooks, reverse verification, scheduled 24-hour invoice and refund reconciliation, and server-calculated `full` and `overpayment` refunds.

## Requirements

- Blesta v5 or v6
- PHP 8.1+ with the `curl`, `hash`, `json`, `openssl`, and `pdo` extensions
- An **HTTPS** Blesta installation URL (plain HTTP is rejected by Connect Paymos)
- A Paymos account with the target project selected in the Paymos Dashboard

No Composer step is required at install time: `paymos/php-sdk` travels bundled inside the release archive at `components/gateways/nonmerchant/paymos/vendor/paymos/php-sdk/`.

## Install and Connect

1. Download the latest release package from [GitHub Releases](https://github.com/Paymos-labs/blesta/releases/latest).
2. Extract the archive into your Blesta web root. It installs:
   - `components/gateways/nonmerchant/paymos/` — the Paymos non-merchant payment gateway;
   - `plugins/paymos/` — the companion Blesta plugin providing 10-minute cron reconciliation (`Reconciler` + `RefundAdapter::run()`) and CSRF-protected staff controllers (`AdminConnect`, `AdminRefunds`).
3. In Blesta admin, install both components:
   - **Settings → Company → Payment Gateways → Available → Paymos → Install**
   - **Settings → Company → Plugins → Available → Paymos Companion → Install**
4. Open the project you want to bill into inside the Paymos Dashboard.
5. Open **Settings → Company → Payment Gateways → Paymos → Manage** in Blesta and click **Connect Paymos**.
6. Approve the connection in the Paymos tab after confirming the installation URL and user code.

That single approval provisions both **Sandbox** and **Live** Payment API credentials (`pk_*` / `sk_*` / `prj_*` / `whsec_*`, plus the `invoice_refunds_v1` refund capability) and registers the webhook callback automatically:

```text
https://your-blesta-domain/callback/gw/1/paymos/
```

### Multi-Company Note

One-Click Connect binds the canonical callback path `/callback/gw/1/paymos/` for Blesta's primary company (`company_id = 1`). Multi-company Blesta installations operating under `company_id != 1` are not provisioned by One-Click Connect.

## Settings & Secret Storage

After connecting, the gateway settings screen exposes only non-secret options:

- **Connection:** read-only status (`Connected` / `Not connected`) and the **Connect Paymos** / **Reconnect Paymos** button.
- **Mode:** `Sandbox (Test)` or `Live (Mainnet)`.
- **Button Text:** label displayed on the Blesta invoice payment button.
- **Webhook Callback URL:** read-only reference showing `/callback/gw/1/paymos/`.

There are zero manual API Key, API Secret, Project ID, or Webhook Secret inputs anywhere in the gateway UI or metadata. All credentials and temporary RFC 8628 device state are sealed in an authenticated `AES-256-GCM` envelope (`AesGcmEnvelope`) derived via HKDF from `Configure::get('Blesta.system_key')`, stored in `paymos_settings`, and never rendered back into HTML or logs.

## Payment Flow, Reliability & Cron Reconciliation

- **Crash-Safe Checkout & `products[]`:** Before sending `POST /v1/invoices`, `CheckoutService` persists a `STATUS_CREATING` intent row. If the process is interrupted before the response is saved, the next render replays the exact `external_order_id`, `project_id`, `amount`, `currency`, and `environment` without minting a duplicate invoice. Up to 50 valid Blesta invoice line items are sent in `products[]` (falling back cleanly to amount-only when invalid or unavailable). Neither `merchant_id` nor client-side TTL is ever sent.
- **Fail-Closed Invoice Replacement:** When an invoice amount, currency, mode, or project changes — or when a previous invoice has ended unpaid (`InvoiceRenewal`) — `CheckoutService` closes the prior invoice via `InvoiceReplacement::close()` before creating a replacement. If the old invoice is still open, already paid, marked `paid_late`, unreachable, or returns 404, replacement is blocked (`InvoiceReplacementBlockedException`) and flagged for manual review.
- **Signed Multi-Environment Webhooks:** `CallbackProcessor` verifies `X-Webhook-Signature` case-insensitively across both Sandbox and Live via `MultiEnvironmentWebhookVerifier` (dual-`v1=` rotation grace, 300-second clock skew tolerance, reject if both environments match). Owner-fenced atomic deduplication returns `200` for committed duplicates, `409` for concurrent in-flight deliveries, and `401` for invalid or stale signatures. Terminal events pass `InvoiceReverseVerifier` (`GET /v1/invoices/{id}`), roll-back guards, and `AmountGuard` (`HTTP 400` + `manual_review` on mismatch). `invoice.paid_late` is recorded as a note and `paid_late_noted` flag without altering payment status.
- **Scheduled Cron Safety Net:** The companion plugin's 10-minute cron task (`paymos_reconcile`) sweeps up to 50 unpaid Paymos invoices from the last 24 hours via `InvoiceReconciler` (throttled to 600 seconds) and reconciles pending and Dashboard-initiated refunds.

## Refunds (`full` & `overpayment`)

Paymos supports server-calculated **full** refunds and **overpayment** (`paid_over`) refunds sent as outbound blockchain transfers strictly to the verified `first_sender` address. Arbitrary partial amounts and unmanaged recipient fields (`destination_address`, `network`, `token`, `crypto_amount`) are rejected before any Paymos API call.

- **Paymos Refunds Admin Panel (`/admin/plugin/paymos/admin_refunds/`):** Staff-only, CSRF-protected interface supporting both `full` and (for `paid_over` invoices) `overpayment` refunds with durable UUIDv4 `request_token` idempotency, `RefundCoordinator` verification (`GET /v1/refunds/{id}` + `GET /v1/invoices/{id}`), and `RefundDiscovery` for refunds initiated directly in the Paymos Dashboard.
- **Native Blesta Refund Hook (`Paymos::refund()`):** Submits a `full` refund through `RefundCoordinator`. Because Paymos refunds settle asynchronously, `Paymos::refund()` returns `null` with an informational notice while the refund is `pending`, `processing`, or `unknown` — never booking a premature accounting debit. Once Paymos confirms `completed` (via `withdrawal.completed` webhook or cron reconciliation), `RefundAdapter::applyCompleted()` projects the refund once via `Transactions::edit` for `full` refunds or records an overpayment note while keeping the original invoice paid for `overpayment` refunds.

## Supported Locales

Bundled native translations are included for all 6 Blesta locales:
`en_us`, `ru_ru`, `de_de`, `es_es`, `tr_tr`, `zh_cn`.

## Links

- [Documentation](https://paymos.io/docs/cms-blesta)
- [Source](https://github.com/Paymos-labs/blesta)
- [Changelog](CHANGELOG.md)
- [Support](mailto:support@paymos.io)
- License: [MIT](LICENSE)

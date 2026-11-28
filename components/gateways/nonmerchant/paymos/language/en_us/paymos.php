<?php

$lang['Paymos.name'] = 'Paymos';
$lang['Paymos.description'] = 'Accept USDT, USDC, USD1, and DAI stablecoins plus gold-backed XAUT in Blesta through hosted checkout, signed webhooks, and full or overpayment refunds.';

$lang['Paymos.connection'] = 'Connection';
$lang['Paymos.connection.connected'] = 'Connected — Sandbox and Live credentials are encrypted in this Blesta installation.';
$lang['Paymos.connection.disconnected'] = 'Not connected — click Connect Paymos and approve this Blesta installation.';
$lang['Paymos.connection.note'] = 'One-Click Connect provisions both Sandbox and Live credentials and registers your webhook automatically.';

$lang['Paymos.connect.button'] = 'Connect Paymos';
$lang['Paymos.connect.reconnect'] = 'Reconnect Paymos';
$lang['Paymos.connect.starting'] = 'Starting connection…';
$lang['Paymos.connect.waiting'] = 'Waiting for approval in Paymos.';
$lang['Paymos.connect.open'] = 'Open the approval page';
$lang['Paymos.connect.blocked'] = 'Your browser blocked the approval tab.';
$lang['Paymos.connect.code'] = 'Code:';
$lang['Paymos.connect.failed'] = 'Could not connect Paymos. Verify HTTPS and try again.';
$lang['Paymos.connect.https_required'] = 'Blesta must be served over HTTPS before connecting Paymos.';

$lang['Paymos.mode'] = 'Mode';
$lang['Paymos.mode.sandbox'] = 'Sandbox (Test)';
$lang['Paymos.mode.live'] = 'Live (Mainnet)';
$lang['Paymos.mode_note'] = 'Use Sandbox for test payments, then switch to Live when ready to accept real orders.';

$lang['Paymos.button_text'] = 'Button Text';
$lang['Paymos.button_text_note'] = 'Label displayed on the Blesta invoice payment button.';
$lang['Paymos.button_text_default'] = 'Pay with Paymos';

$lang['Paymos.webhook_url'] = 'Webhook Callback URL';
$lang['Paymos.webhook_url_note'] = 'Registered automatically by Connect Paymos for company 1 (/callback/gw/1/paymos/).';

$lang['Paymos.buildprocess.submit'] = 'Pay with Paymos';
$lang['Paymos.buildprocess.note'] = 'You will be redirected to Paymos Checkout to complete payment with USDT, USDC, USD1, DAI, or XAUT.';

$lang['Paymos.refunds.title'] = 'Paymos Refunds';
$lang['Paymos.refunds.operation'] = 'Operation';
$lang['Paymos.refunds.type'] = 'Type';
$lang['Paymos.refunds.cash_status'] = 'Payout status';
$lang['Paymos.refunds.blesta_record'] = 'Blesta record';
$lang['Paymos.refunds.review_reason'] = 'Review reason';
$lang['Paymos.refunds.amount'] = 'Amount';
$lang['Paymos.refunds.button_full'] = 'Refund in full';
$lang['Paymos.refunds.button_overpayment'] = 'Refund overpayment';
$lang['Paymos.refunds.external_badge'] = 'Refund initiated in Paymos Dashboard';
$lang['Paymos.refunds.empty'] = 'No Paymos refunds recorded for this invoice yet.';
$lang['Paymos.refunds.reconnect_hint'] = 'Refunds are not enabled on this connection. Click Reconnect Paymos in gateway settings.';
$lang['Paymos.refunds.native_in_progress'] = 'Paymos refund is in progress. Blesta will mark the transaction refunded automatically once Paymos confirms completion; track progress in Paymos Refunds.';
$lang['Paymos.refunds.native_failed'] = 'Paymos refund could not be completed. Check details in Paymos Refunds.';

$lang['Paymos.!error.mode.valid'] = 'Select a valid mode: Sandbox or Live.';
$lang['Paymos.!error.button_text.length'] = 'Button text must be 100 characters or fewer.';
$lang['Paymos.!error.not_connected'] = 'Paymos is not connected yet. Open Paymos gateway settings and click Connect Paymos.';
$lang['Paymos.!error.checkout.failed'] = 'Paymos is temporarily unavailable. Please try again or contact support.';
$lang['Paymos.!error.payment_cannot_be_used'] = 'This payment method cannot be used for this invoice.';
$lang['Paymos.!error.replacement_blocked'] = 'This invoice needs store review before payment can continue. Please contact support.';
$lang['Paymos.!error.webhook.invalid'] = 'Paymos webhook verification failed.';
$lang['Paymos.!error.refund.partial_unsupported'] = 'Partial refunds are not supported. Paymos supports only server-calculated full and overpayment refunds.';
$lang['Paymos.!error.refund.failed'] = 'Could not submit the refund request to Paymos.';
$lang['Paymos.!error.refund.access_denied'] = 'Administrator authorization is required to manage Paymos refunds.';

$lang['Paymos.note.paid_late.title'] = 'Paymos: payment after the invoice closed';
$lang['Paymos.note.paid_late.body'] = 'Paymos invoice {paymos_invoice_id} ({amount} {currency}) was paid after it expired or was underpaid. Blesta did not record this payment, and new Paymos payments for this invoice are blocked. Check the service, then record the payment manually or refund it in the Paymos Dashboard.';
$lang['Paymos.note.manual_review.title'] = 'Paymos: payment needs review';
$lang['Paymos.note.manual_review.amount_mismatch'] = 'Paymos invoice {paymos_invoice_id} was paid ({amount} {currency}), but the amount or currency no longer matches the Blesta invoice. Blesta did not record this payment. Check the invoice, then record the payment manually or refund it in the Paymos Dashboard.';
$lang['Paymos.note.manual_review.already_paid'] = 'Paymos invoice {paymos_invoice_id} was paid ({amount} {currency}) after the Blesta invoice had been paid another way. Blesta did not record this payment. Add it to the client credit manually or refund it in the Paymos Dashboard.';
$lang['Paymos.refunds.lookup_label'] = 'Blesta invoice ID';
$lang['Paymos.refunds.lookup_submit'] = 'Show';
$lang['Paymos.refunds.recent_invoice'] = 'Invoice #{id}';
$lang['Paymos.refunds.submit_status'] = 'Refund status: {status}';
$lang['Paymos.refunds.network_error'] = 'Network error: the refund was not sent. Try again.';
$lang['Paymos.refunds.invoice_required'] = 'Enter a valid Blesta invoice ID.';
$lang['Paymos.!error.refund.credit_unsupported'] = 'This Paymos payment added account credit and is not tied to an invoice, so it cannot be refunded from Blesta. Refund it in the Paymos Dashboard and adjust the client credit manually.';
$lang['Paymos.refunds.payment_label'] = 'Payment {paymos_invoice_id}: {amount} {currency}';
$lang['Paymos.!error.refund.payment_selection_required'] = 'This invoice has several Paymos payments. Choose the payment to refund on the Paymos Refunds page.';

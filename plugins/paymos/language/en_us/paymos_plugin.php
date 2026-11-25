<?php

$lang['PaymosPlugin.name'] = 'Paymos Companion';
$lang['PaymosPlugin.description'] = 'Provides scheduled invoice and refund reconciliation plus admin Connect Paymos and Paymos Refunds endpoints for the Paymos payment gateway.';
$lang['PaymosPlugin.cron.reconcile_name'] = 'Paymos Invoice & Refund Reconciliation';
$lang['PaymosPlugin.cron.reconcile_desc'] = 'Sweeps unpaid Paymos invoices from the last 24 hours and reconciles pending or Dashboard-initiated Paymos refunds.';
$lang['PaymosPlugin.nav_secondary_staff.refunds'] = 'Paymos Refunds';
$lang['PaymosPlugin.error.access_denied'] = 'Administrator authorization is required.';
$lang['PaymosPlugin.error.csrf_invalid'] = 'Invalid CSRF token. Reload the page and try again.';
$lang['PaymosPlugin.error.method_not_allowed'] = 'POST request is required.';
$lang['PaymosPlugin.error.not_connected'] = 'Paymos is not connected. Open Paymos gateway settings and click Connect Paymos.';

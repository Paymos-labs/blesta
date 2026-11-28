<?php

$lang['PaymosPlugin.name'] = 'Paymos-Begleit-Plugin';
$lang['PaymosPlugin.description'] = 'Gleicht Paymos-Rechnungen und -Erstattungen zeitgesteuert ab und stellt für das Paymos-Zahlungsgateway die Admin-Seiten „Paymos verbinden“ und „Paymos-Erstattungen“ bereit.';
$lang['PaymosPlugin.cron.reconcile_name'] = 'Abgleich von Paymos-Rechnungen und -Erstattungen';
$lang['PaymosPlugin.cron.reconcile_desc'] = 'Prüft unbezahlte Paymos-Rechnungen der letzten 24 Stunden und gleicht offene oder im Paymos-Dashboard gestartete Paymos-Erstattungen ab.';
$lang['PaymosPlugin.nav_secondary_staff.refunds'] = 'Paymos-Erstattungen';
$lang['PaymosPlugin.error.access_denied'] = 'Administratorrechte erforderlich.';
$lang['PaymosPlugin.error.csrf_invalid'] = 'Ungültiges CSRF-Token. Laden Sie die Seite neu und versuchen Sie es erneut.';
$lang['PaymosPlugin.error.method_not_allowed'] = 'Eine POST-Anfrage ist erforderlich.';
$lang['PaymosPlugin.error.not_connected'] = 'Paymos ist nicht verbunden. Öffnen Sie die Paymos-Gateway-Einstellungen und klicken Sie auf „Paymos verbinden“.';
$lang['PaymosPlugin.error.invalid_action'] = 'Unbekannte Verbindungsaktion.';
$lang['PaymosPlugin.permission.admin_main'] = 'Paymos';
$lang['PaymosPlugin.permission.admin_connect'] = 'Paymos verbinden';
$lang['PaymosPlugin.permission.admin_refunds'] = 'Paymos-Erstattungen';

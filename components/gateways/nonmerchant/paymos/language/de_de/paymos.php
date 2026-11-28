<?php

$lang['Paymos.name'] = 'Paymos';
$lang['Paymos.description'] = 'Akzeptieren Sie USDT, USDC, USD1, DAI und goldgedecktes XAUT in Blesta über Paymos Checkout mit signierten Webhooks sowie vollständigen und Überzahlungserstattungen.';

$lang['Paymos.connection'] = 'Verbindung';
$lang['Paymos.connection.connected'] = 'Verbunden — Sandbox- und Live-Zugangsdaten sind in dieser Blesta-Installation verschlüsselt gespeichert.';
$lang['Paymos.connection.disconnected'] = 'Nicht verbunden — klicken Sie auf „Paymos verbinden“ und bestätigen Sie diese Blesta-Installation.';
$lang['Paymos.connection.note'] = 'One-Click Connect richtet Sandbox und Live gleichzeitig ein und registriert Ihren Webhook automatisch.';

$lang['Paymos.connect.button'] = 'Paymos verbinden';
$lang['Paymos.connect.reconnect'] = 'Paymos neu verbinden';
$lang['Paymos.connect.starting'] = 'Verbindung wird gestartet…';
$lang['Paymos.connect.waiting'] = 'Warten auf Bestätigung in Paymos.';
$lang['Paymos.connect.open'] = 'Bestätigungsseite öffnen';
$lang['Paymos.connect.blocked'] = 'Ihr Browser hat den Bestätigungs-Tab blockiert.';
$lang['Paymos.connect.code'] = 'Code:';
$lang['Paymos.connect.failed'] = 'Paymos konnte nicht verbunden werden. Prüfen Sie HTTPS und versuchen Sie es erneut.';
$lang['Paymos.connect.https_required'] = 'Blesta muss über HTTPS erreichbar sein, bevor Paymos verbunden werden kann.';

$lang['Paymos.mode'] = 'Modus';
$lang['Paymos.mode.sandbox'] = 'Sandbox (Test)';
$lang['Paymos.mode.live'] = 'Live (Mainnet)';
$lang['Paymos.mode_note'] = 'Nutzen Sie Sandbox für Testzahlungen und wechseln Sie zu Live, sobald Sie echte Rechnungen abrechnen möchten.';

$lang['Paymos.button_text'] = 'Buttontext';
$lang['Paymos.button_text_note'] = 'Beschriftung der Zahlungsschaltfläche auf der Blesta-Rechnung.';
$lang['Paymos.button_text_default'] = 'Mit Paymos bezahlen';

$lang['Paymos.webhook_url'] = 'Webhook-Callback-URL';
$lang['Paymos.webhook_url_note'] = 'Wird von „Paymos verbinden“ automatisch für Unternehmen 1 (/callback/gw/1/paymos/) registriert.';

$lang['Paymos.buildprocess.submit'] = 'Mit Paymos bezahlen';
$lang['Paymos.buildprocess.note'] = 'Sie werden zu Paymos Checkout weitergeleitet, um mit USDT, USDC, USD1, DAI oder XAUT zu bezahlen.';

$lang['Paymos.refunds.title'] = 'Paymos-Erstattungen';
$lang['Paymos.refunds.operation'] = 'Vorgang';
$lang['Paymos.refunds.type'] = 'Typ';
$lang['Paymos.refunds.cash_status'] = 'Auszahlungsstatus';
$lang['Paymos.refunds.blesta_record'] = 'Blesta-Eintrag';
$lang['Paymos.refunds.review_reason'] = 'Prüfgrund';
$lang['Paymos.refunds.amount'] = 'Betrag';
$lang['Paymos.refunds.button_full'] = 'Vollständig erstatten';
$lang['Paymos.refunds.button_overpayment'] = 'Überzahlung erstatten';
$lang['Paymos.refunds.external_badge'] = 'Erstattung über das Paymos-Dashboard gestartet';
$lang['Paymos.refunds.empty'] = 'Für diese Rechnung sind noch keine Paymos-Erstattungen vorhanden.';
$lang['Paymos.refunds.reconnect_hint'] = 'Erstattungen sind für diese Verbindung nicht aktiviert. Klicken Sie in den Gateway-Einstellungen auf „Paymos neu verbinden“.';
$lang['Paymos.refunds.native_in_progress'] = 'Die Paymos-Erstattung läuft. Blesta markiert die Transaktion automatisch als erstattet, sobald Paymos den Abschluss bestätigt; verfolgen Sie den Status unter „Paymos-Erstattungen“.';
$lang['Paymos.refunds.native_failed'] = 'Die Paymos-Erstattung konnte nicht abgeschlossen werden. Prüfen Sie die Details unter „Paymos-Erstattungen“.';

$lang['Paymos.!error.mode.valid'] = 'Wählen Sie einen gültigen Modus: Sandbox oder Live.';
$lang['Paymos.!error.button_text.length'] = 'Der Buttontext darf höchstens 100 Zeichen lang sein.';
$lang['Paymos.!error.not_connected'] = 'Paymos ist noch nicht verbunden. Öffnen Sie die Gateway-Einstellungen und klicken Sie auf „Paymos verbinden“.';
$lang['Paymos.!error.checkout.failed'] = 'Paymos ist vorübergehend nicht verfügbar. Bitte versuchen Sie es erneut oder wenden Sie sich an den Support.';
$lang['Paymos.!error.payment_cannot_be_used'] = 'Diese Zahlungsart kann für diese Rechnung nicht verwendet werden.';
$lang['Paymos.!error.replacement_blocked'] = 'Diese Rechnung muss vor der weiteren Zahlung geprüft werden. Bitte wenden Sie sich an den Support.';
$lang['Paymos.!error.webhook.invalid'] = 'Die Paymos-Webhook-Prüfung ist fehlgeschlagen.';
$lang['Paymos.!error.refund.partial_unsupported'] = 'Teilerstattungen werden nicht unterstützt. Paymos unterstützt nur serverseitig berechnete Voll- und Überzahlungserstattungen.';
$lang['Paymos.!error.refund.failed'] = 'Die Erstattungsanfrage konnte nicht an Paymos gesendet werden.';
$lang['Paymos.!error.refund.access_denied'] = 'Zum Verwalten von Paymos-Erstattungen sind Administratorrechte erforderlich.';

$lang['Paymos.note.paid_late.title'] = 'Paymos: Zahlung nach Schließung der Rechnung';
$lang['Paymos.note.paid_late.body'] = 'Die Paymos-Rechnung {paymos_invoice_id} ({amount} {currency}) wurde bezahlt, nachdem sie abgelaufen oder unterbezahlt war. Blesta hat diese Zahlung nicht verbucht, und neue Paymos-Zahlungen für diese Rechnung sind gesperrt. Prüfen Sie den Dienst und verbuchen Sie die Zahlung dann manuell oder erstatten Sie sie im Paymos-Dashboard.';
$lang['Paymos.note.manual_review.title'] = 'Paymos: Zahlung muss geprüft werden';
$lang['Paymos.note.manual_review.amount_mismatch'] = 'Die Paymos-Rechnung {paymos_invoice_id} wurde bezahlt ({amount} {currency}), aber Betrag oder Währung stimmen nicht mehr mit der Blesta-Rechnung überein. Blesta hat diese Zahlung nicht verbucht. Prüfen Sie die Rechnung und verbuchen Sie die Zahlung dann manuell oder erstatten Sie sie im Paymos-Dashboard.';
$lang['Paymos.note.manual_review.already_paid'] = 'Die Paymos-Rechnung {paymos_invoice_id} wurde bezahlt ({amount} {currency}), nachdem die Blesta-Rechnung bereits auf anderem Weg bezahlt war. Blesta hat diese Zahlung nicht verbucht. Schreiben Sie sie dem Kunden manuell als Guthaben gut oder erstatten Sie sie im Paymos-Dashboard.';
$lang['Paymos.refunds.lookup_label'] = 'Blesta-Rechnungsnummer';
$lang['Paymos.refunds.lookup_submit'] = 'Anzeigen';
$lang['Paymos.refunds.recent_invoice'] = 'Rechnung #{id}';
$lang['Paymos.refunds.submit_status'] = 'Erstattungsstatus: {status}';
$lang['Paymos.refunds.network_error'] = 'Netzwerkfehler: Die Erstattung wurde nicht gesendet. Versuchen Sie es erneut.';
$lang['Paymos.refunds.invoice_required'] = 'Geben Sie eine gültige Blesta-Rechnungsnummer ein.';
$lang['Paymos.!error.refund.credit_unsupported'] = 'Diese Paymos-Zahlung hat das Kundenguthaben aufgeladen und gehört zu keiner Rechnung, daher kann sie nicht aus Blesta erstattet werden. Erstatten Sie sie im Paymos-Dashboard und passen Sie das Kundenguthaben manuell an.';
$lang['Paymos.refunds.payment_label'] = 'Zahlung {paymos_invoice_id}: {amount} {currency}';
$lang['Paymos.!error.refund.payment_selection_required'] = 'Diese Rechnung hat mehrere Paymos-Zahlungen. Wählen Sie auf der Seite „Paymos-Erstattungen“ die Zahlung, die erstattet werden soll.';

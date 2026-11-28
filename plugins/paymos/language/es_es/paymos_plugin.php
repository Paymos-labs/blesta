<?php

$lang['PaymosPlugin.name'] = 'Complemento de Paymos';
$lang['PaymosPlugin.description'] = 'Concilia facturas y reembolsos de Paymos de forma programada y añade a la pasarela de pago Paymos las páginas de administración «Conectar Paymos» y «Reembolsos de Paymos».';
$lang['PaymosPlugin.cron.reconcile_name'] = 'Conciliación de facturas y reembolsos de Paymos';
$lang['PaymosPlugin.cron.reconcile_desc'] = 'Revisa las facturas de Paymos sin pagar de las últimas 24 horas y concilia los reembolsos de Paymos pendientes o iniciados desde el panel de Paymos.';
$lang['PaymosPlugin.nav_secondary_staff.refunds'] = 'Reembolsos de Paymos';
$lang['PaymosPlugin.error.access_denied'] = 'Se requiere autorización de administrador.';
$lang['PaymosPlugin.error.csrf_invalid'] = 'Token CSRF no válido. Recarga la página e inténtalo de nuevo.';
$lang['PaymosPlugin.error.method_not_allowed'] = 'Se requiere una solicitud POST.';
$lang['PaymosPlugin.error.not_connected'] = 'Paymos no está conectado. Abre los ajustes de la pasarela Paymos y haz clic en «Conectar Paymos».';
$lang['PaymosPlugin.error.invalid_action'] = 'Acción de conexión desconocida.';
$lang['PaymosPlugin.permission.admin_main'] = 'Paymos';
$lang['PaymosPlugin.permission.admin_connect'] = 'Conectar Paymos';
$lang['PaymosPlugin.permission.admin_refunds'] = 'Reembolsos de Paymos';

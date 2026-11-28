<?php

$lang['PaymosPlugin.name'] = 'Paymos Yardımcı Eklentisi';
$lang['PaymosPlugin.description'] = 'Paymos faturalarını ve iadelerini zamanlanmış olarak mutabık kılar ve Paymos ödeme ağ geçidi için “Paymos’u bağla” ile “Paymos İadeleri” yönetici sayfalarını ekler.';
$lang['PaymosPlugin.cron.reconcile_name'] = 'Paymos fatura ve iade mutabakatı';
$lang['PaymosPlugin.cron.reconcile_desc'] = 'Son 24 saatteki ödenmemiş Paymos faturalarını tarar ve bekleyen ya da Paymos panelinden başlatılan Paymos iadelerini mutabık kılar.';
$lang['PaymosPlugin.nav_secondary_staff.refunds'] = 'Paymos İadeleri';
$lang['PaymosPlugin.error.access_denied'] = 'Yönetici yetkisi gerekiyor.';
$lang['PaymosPlugin.error.csrf_invalid'] = 'Geçersiz CSRF belirteci. Sayfayı yenileyip tekrar deneyin.';
$lang['PaymosPlugin.error.method_not_allowed'] = 'POST isteği gerekiyor.';
$lang['PaymosPlugin.error.not_connected'] = 'Paymos bağlı değil. Paymos ağ geçidi ayarlarını açın ve “Paymos’u bağla” düğmesine tıklayın.';
$lang['PaymosPlugin.error.invalid_action'] = 'Bilinmeyen bağlantı işlemi.';
$lang['PaymosPlugin.permission.admin_main'] = 'Paymos';
$lang['PaymosPlugin.permission.admin_connect'] = 'Paymos bağlantısı';
$lang['PaymosPlugin.permission.admin_refunds'] = 'Paymos İadeleri';

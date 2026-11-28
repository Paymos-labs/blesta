<?php

$lang['PaymosPlugin.name'] = 'Paymos 配套插件';
$lang['PaymosPlugin.description'] = '按计划对账 Paymos 账单和退款，并为 Paymos 支付网关提供「连接 Paymos」和「Paymos 退款」管理页面。';
$lang['PaymosPlugin.cron.reconcile_name'] = 'Paymos 账单与退款对账';
$lang['PaymosPlugin.cron.reconcile_desc'] = '检查最近 24 小时内未支付的 Paymos 账单，并对账待处理或在 Paymos 控制台中发起的 Paymos 退款。';
$lang['PaymosPlugin.nav_secondary_staff.refunds'] = 'Paymos 退款';
$lang['PaymosPlugin.error.access_denied'] = '需要管理员权限。';
$lang['PaymosPlugin.error.csrf_invalid'] = 'CSRF 令牌无效。请刷新页面后重试。';
$lang['PaymosPlugin.error.method_not_allowed'] = '需要 POST 请求。';
$lang['PaymosPlugin.error.not_connected'] = 'Paymos 尚未连接。请打开 Paymos 网关设置并点击「连接 Paymos」。';
$lang['PaymosPlugin.error.invalid_action'] = '未知的连接操作。';
$lang['PaymosPlugin.permission.admin_main'] = 'Paymos';
$lang['PaymosPlugin.permission.admin_connect'] = '连接 Paymos';
$lang['PaymosPlugin.permission.admin_refunds'] = 'Paymos 退款';

<?php

$lang['PaymosPlugin.name'] = 'Paymos: служебный плагин';
$lang['PaymosPlugin.description'] = 'Сверяет счета и возвраты Paymos по расписанию и добавляет для платёжного модуля Paymos страницы «Подключить Paymos» и «Возвраты Paymos» в панели администратора.';
$lang['PaymosPlugin.cron.reconcile_name'] = 'Сверка счетов и возвратов Paymos';
$lang['PaymosPlugin.cron.reconcile_desc'] = 'Проверяет неоплаченные счета Paymos за последние 24 часа и сверяет возвраты Paymos: ожидающие и созданные в кабинете Paymos.';
$lang['PaymosPlugin.nav_secondary_staff.refunds'] = 'Возвраты Paymos';
$lang['PaymosPlugin.error.access_denied'] = 'Нужна авторизация администратора.';
$lang['PaymosPlugin.error.csrf_invalid'] = 'Недействительный CSRF-токен. Обновите страницу и попробуйте ещё раз.';
$lang['PaymosPlugin.error.method_not_allowed'] = 'Нужен POST-запрос.';
$lang['PaymosPlugin.error.not_connected'] = 'Paymos не подключён. Откройте настройки модуля Paymos и нажмите «Подключить Paymos».';
$lang['PaymosPlugin.error.invalid_action'] = 'Неизвестное действие подключения.';
$lang['PaymosPlugin.permission.admin_main'] = 'Paymos';
$lang['PaymosPlugin.permission.admin_connect'] = 'Подключение Paymos';
$lang['PaymosPlugin.permission.admin_refunds'] = 'Возвраты Paymos';

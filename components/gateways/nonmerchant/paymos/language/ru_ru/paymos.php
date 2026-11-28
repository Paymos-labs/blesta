<?php

$lang['Paymos.name'] = 'Paymos';
$lang['Paymos.description'] = 'Принимайте USDT, USDC, USD1, DAI и XAUT в Blesta через страницу оплаты Paymos с подписанными вебхуками, сверкой и возвратами.';

$lang['Paymos.connection'] = 'Подключение';
$lang['Paymos.connection.connected'] = 'Подключено — ключи Sandbox и Live зашифрованы в этой установке Blesta.';
$lang['Paymos.connection.disconnected'] = 'Не подключено — нажмите «Подключить Paymos» и подтвердите эту установку Blesta.';
$lang['Paymos.connection.note'] = 'Подключение в один клик сразу выдаёт ключи для Sandbox и Live и регистрирует адрес вебхука.';

$lang['Paymos.connect.button'] = 'Подключить Paymos';
$lang['Paymos.connect.reconnect'] = 'Переподключить Paymos';
$lang['Paymos.connect.starting'] = 'Запускаем подключение…';
$lang['Paymos.connect.waiting'] = 'Ждём подтверждения в Paymos.';
$lang['Paymos.connect.open'] = 'Открыть страницу подтверждения';
$lang['Paymos.connect.blocked'] = 'Браузер заблокировал вкладку подтверждения.';
$lang['Paymos.connect.code'] = 'Код:';
$lang['Paymos.connect.failed'] = 'Не удалось подключить Paymos. Проверьте HTTPS и попробуйте снова.';
$lang['Paymos.connect.https_required'] = 'Чтобы подключить Paymos, Blesta должна работать по HTTPS.';

$lang['Paymos.mode'] = 'Режим';
$lang['Paymos.mode.sandbox'] = 'Песочница (Sandbox)';
$lang['Paymos.mode.live'] = 'Боевой (Live)';
$lang['Paymos.mode_note'] = 'Сначала проверьте тестовые оплаты в песочнице, а потом переключитесь на боевой режим для реальных счетов.';

$lang['Paymos.button_text'] = 'Текст кнопки';
$lang['Paymos.button_text_note'] = 'Подпись на кнопке оплаты в счёте Blesta.';
$lang['Paymos.button_text_default'] = 'Оплатить через Paymos';

$lang['Paymos.webhook_url'] = 'URL для вебхуков';
$lang['Paymos.webhook_url_note'] = 'Регистрируется автоматически при подключении Paymos для компании 1 (/callback/gw/1/paymos/).';

$lang['Paymos.buildprocess.submit'] = 'Оплатить через Paymos';
$lang['Paymos.buildprocess.note'] = 'Вы перейдёте на страницу оплаты Paymos, чтобы оплатить счёт в USDT, USDC, USD1, DAI или XAUT.';

$lang['Paymos.refunds.title'] = 'Возвраты Paymos';
$lang['Paymos.refunds.operation'] = 'Операция';
$lang['Paymos.refunds.type'] = 'Тип';
$lang['Paymos.refunds.cash_status'] = 'Статус выплаты';
$lang['Paymos.refunds.blesta_record'] = 'Запись в Blesta';
$lang['Paymos.refunds.review_reason'] = 'Причина проверки';
$lang['Paymos.refunds.amount'] = 'Сумма';
$lang['Paymos.refunds.button_full'] = 'Полный возврат';
$lang['Paymos.refunds.button_overpayment'] = 'Вернуть переплату';
$lang['Paymos.refunds.external_badge'] = 'Возврат создан в кабинете Paymos';
$lang['Paymos.refunds.empty'] = 'По этому счёту возвратов Paymos ещё нет.';
$lang['Paymos.refunds.reconnect_hint'] = 'Для этого подключения возвраты недоступны. Нажмите «Переподключить Paymos» в настройках модуля.';
$lang['Paymos.refunds.native_in_progress'] = 'Возврат через Paymos в обработке. Blesta отметит транзакцию как возвращённую сразу после подтверждения выплаты; следите за статусом в разделе «Возвраты Paymos».';
$lang['Paymos.refunds.native_failed'] = 'Не удалось завершить возврат через Paymos. Подробности смотрите в разделе «Возвраты Paymos».';

$lang['Paymos.!error.mode.valid'] = 'Выберите режим: «Песочница» или «Боевой».';
$lang['Paymos.!error.button_text.length'] = 'Текст кнопки не должен быть длиннее 100 символов.';
$lang['Paymos.!error.not_connected'] = 'Paymos ещё не подключён. Откройте настройки модуля и нажмите «Подключить Paymos».';
$lang['Paymos.!error.checkout.failed'] = 'Paymos временно недоступен. Попробуйте ещё раз или обратитесь в поддержку.';
$lang['Paymos.!error.payment_cannot_be_used'] = 'Этот способ оплаты не подходит для этого счёта.';
$lang['Paymos.!error.replacement_blocked'] = 'Счёт нужно проверить перед продолжением оплаты. Обратитесь в поддержку.';
$lang['Paymos.!error.webhook.invalid'] = 'Проверка подписи вебхука Paymos не прошла.';
$lang['Paymos.!error.refund.partial_unsupported'] = 'Частичные возвраты не поддерживаются. В Paymos доступны только полный возврат и возврат переплаты.';
$lang['Paymos.!error.refund.failed'] = 'Не удалось отправить запрос на возврат в Paymos.';
$lang['Paymos.!error.refund.access_denied'] = 'Для работы с возвратами Paymos нужны права администратора.';

$lang['Paymos.note.paid_late.title'] = 'Paymos: оплата после закрытия счёта';
$lang['Paymos.note.paid_late.body'] = 'Счёт Paymos {paymos_invoice_id} ({amount} {currency}) оплачен после того, как истёк или был оплачен не полностью. Blesta эту оплату не записала, новые оплаты этого счёта через Paymos заблокированы. Проверьте услугу, затем запишите оплату вручную или верните её в кабинете Paymos.';
$lang['Paymos.note.manual_review.title'] = 'Paymos: оплату нужно проверить';
$lang['Paymos.note.manual_review.amount_mismatch'] = 'Счёт Paymos {paymos_invoice_id} оплачен ({amount} {currency}), но сумма или валюта больше не совпадает со счётом Blesta. Blesta эту оплату не записала. Проверьте счёт, затем запишите оплату вручную или верните её в кабинете Paymos.';
$lang['Paymos.note.manual_review.already_paid'] = 'Счёт Paymos {paymos_invoice_id} оплачен ({amount} {currency}) после того, как счёт Blesta уже оплатили другим способом. Blesta эту оплату не записала. Зачислите её на баланс клиента вручную или верните в кабинете Paymos.';
$lang['Paymos.refunds.lookup_label'] = 'Номер счёта Blesta';
$lang['Paymos.refunds.lookup_submit'] = 'Показать';
$lang['Paymos.refunds.recent_invoice'] = 'Счёт №{id}';
$lang['Paymos.refunds.submit_status'] = 'Статус возврата: {status}';
$lang['Paymos.refunds.network_error'] = 'Ошибка сети: возврат не отправлен. Попробуйте ещё раз.';
$lang['Paymos.refunds.invoice_required'] = 'Укажите номер счёта Blesta.';
$lang['Paymos.!error.refund.credit_unsupported'] = 'Эта оплата Paymos пополнила баланс клиента и не привязана к счёту, поэтому вернуть её из Blesta нельзя. Сделайте возврат в кабинете Paymos и скорректируйте баланс клиента вручную.';
$lang['Paymos.refunds.payment_label'] = 'Оплата {paymos_invoice_id}: {amount} {currency}';
$lang['Paymos.!error.refund.payment_selection_required'] = 'У этого счёта несколько оплат Paymos. Выберите оплату для возврата на странице «Возвраты Paymos».';

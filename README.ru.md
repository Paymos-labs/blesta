# Paymos для Blesta

[English](README.md) | [简体中文](README.zh-CN.md) | **Русский** | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Официальный немерчантский платёжный шлюз Paymos и сопутствующий плагин сверки для **Blesta v5 и v6**.

Принимайте четыре стейблкоина (**USDT**, **USDC**, **USD1**, **DAI**) и обеспеченный золотом **XAUT** на платёжной странице Paymos. В комплект входят подключение в один клик **Подключить Paymos**, шифрование ключей AES-256-GCM, отказоустойчивое создание счетов с товарными позициями (`products[]`), подписанные вебхуки для обеих сред, обратная проверка, плановая 24-часовая сверка счетов и возвратов, а также возвраты `full` и `overpayment` с расчётом суммы на сервере.

[![Видео: 12 официальных CMS-плагинов Paymos — подключение в один клик](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Видеообзор (1:12, субтитры на 6 языках):** [Смотреть на YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Требования

- Blesta v5 или v6
- PHP 8.1+ с расширениями `curl`, `hash`, `json`, `openssl` и `pdo`
- URL установки Blesta по **HTTPS** (подключение по обычному HTTP отклоняется)
- Аккаунт Paymos с выбранным целевым проектом в кабинете Paymos

Запускать Composer при установке не требуется: библиотека `paymos/php-sdk` уже включена в релизный архив по пути `components/gateways/nonmerchant/paymos/vendor/paymos/php-sdk/`.

## Установка и подключение

1. Скачайте актуальный архив со страницы [GitHub Releases](https://github.com/Paymos-labs/blesta/releases/latest).
2. Распакуйте архив в корень веб-директории Blesta. Он установит два компонента:
   - `components/gateways/nonmerchant/paymos/` — немерчантский платёжный шлюз Paymos;
   - `plugins/paymos/` — сопутствующий плагин Blesta для сверки по cron каждые 10 минут (`Reconciler` + `RefundAdapter::run()`) и защищённых CSRF контроллеров панели сотрудников (`AdminConnect`, `AdminRefunds`).
3. Установите оба компонента в админке Blesta:
   - **Settings → Company → Payment Gateways → Available → Paymos → Install**
   - **Settings → Company → Plugins → Available → Paymos Companion → Install**
4. Откройте в кабинете Paymos проект, на который должны выставляться счета.
5. В Blesta перейдите в **Settings → Company → Payment Gateways → Paymos → Manage** и нажмите **Подключить Paymos** (Connect Paymos).
6. Проверьте URL установки и код подтверждения, затем одобрите подключение во вкладке Paymos.

Одно подтверждение сразу настраивает учётные данные Payment API для **Песочницы** (Sandbox) и **Рабочего режима** (Live) (`pk_*` / `sk_*` / `prj_*` / `whsec_*`, включая право возвратов `invoice_refunds_v1`) и автоматически регистрирует адрес вебхука:

```text
https://your-blesta-domain/callback/gw/1/paymos/
```

### Примечание по мультикампанейным установкам

Подключение в один клик привязывает канонический путь вебхука `/callback/gw/1/paymos/` к основной компании Blesta (`company_id = 1`). Мультикампанейные установки Blesta с `company_id != 1` через One-Click Connect не настраиваются.

## Настройки и хранение секретов

После подключения на экране настроек шлюза остаются только несекретные поля:

- **Подключение (Connection):** статус только для чтения (`Connected` / `Not connected`) и кнопка **Подключить Paymos** / **Переподключить Paymos**.
- **Режим (Mode):** `Sandbox (Test)` или `Live (Mainnet)`.
- **Текст кнопки (Button Text):** подпись на кнопке оплаты в счёте Blesta.
- **URL вебхука (Webhook Callback URL):** справочное поле только для чтения с путём `/callback/gw/1/paymos/`.

В интерфейсе шлюза и его метаданных нет ни одного ручного поля для API Key, API Secret, Project ID или Webhook Secret. Все ключи и временное состояние привязки устройства по RFC 8628 шифруются в аутентифицированном конверте `AES-256-GCM` (`AesGcmEnvelope`) на ключе, выведенном через HKDF из `Configure::get('Blesta.system_key')`, хранятся в таблице `paymos_settings` и никогда не выводятся в HTML или логи.

## Оплата, отказоустойчивость и сверка по cron

- **Отказоустойчивая оплата и `products[]`:** перед отправкой `POST /v1/invoices` сервис `CheckoutService` сохраняет строку намерения `STATUS_CREATING`. Если процесс оборвётся до записи ответа, следующий запрос повторит те же `external_order_id`, `project_id`, `amount`, `currency` и `environment`, не создавая дубликат счёта. До 50 корректных строк счёта Blesta передаются в `products[]` (если позиции недоступны или некорректны, отправляется только итоговая сумма). Поле `merchant_id` и клиентский TTL никогда не передаются.
- **Безопасная замена счёта (fail-closed):** когда меняется сумма счёта, валюта, режим или проект — либо предыдущий счёт завершился без оплаты (`InvoiceRenewal`) — `CheckoutService` сначала закрывает прежний инвойс через `InvoiceReplacement::close()`, и лишь затем создаёт новый. Если старый счёт ещё открыт, уже оплачен, помечен как `paid_late`, недоступен или вернул 404, замена блокируется (`InvoiceReplacementBlockedException`) и отправляется на ручную проверку.
- **Подписанные вебхуки для обеих сред:** `CallbackProcessor` проверяет заголовок `X-Webhook-Signature` без учёта регистра сразу для Песочницы и Рабочего режима через `MultiEnvironmentWebhookVerifier` (поддержка двойного `v1=` при ротации секрета, допуск расхождения часов 300 секунд, отказ при совпадении обеих сред одновременно). Атомарная дедупликация с привязкой к владельцу возвращает `200` на обработанные дубли, `409` на параллельные доставки в работе и `401` на неверную или просроченную подпись. Финальные события проходят `InvoiceReverseVerifier` (`GET /v1/invoices/{id}`), защиту от отката и `AmountGuard` (`HTTP 400` + `manual_review` при расхождении). `invoice.paid_late` сохраняется как заметка и флаг `paid_late_noted`, не меняя статус оплаты.
- **Страховка по расписанию cron:** задача `paymos_reconcile` в сопутствующем плагине запускается каждые 10 минут, проверяет через `InvoiceReconciler` до 50 неоплаченных счетов Paymos за последние 24 часа (с ограничением не чаще раза в 600 секунд) и синхронизирует ожидающие возвраты и возвраты, запущенные в кабинете Paymos.

## Возвраты (`full` и `overpayment`)

Paymos поддерживает серверные **полные** возвраты (`full`) и возвраты **переплаты** (`overpayment`, для счетов `paid_over`). Средства уходят исходящей транзакцией в блокчейне строго на проверенный адрес `first_sender`. Произвольные частичные суммы и неуправляемые поля получателя (`destination_address`, `network`, `token`, `crypto_amount`) отклоняются до отправки любого запроса в Paymos API.

- **Панель возвратов Paymos (`/admin/plugin/paymos/admin_refunds/`):** интерфейс только для сотрудников с защитой CSRF, поддерживающий возвраты `full` и (для счетов `paid_over`) `overpayment` с идемпотентностью по постоянному UUIDv4 `request_token`, проверкой через `RefundCoordinator` (`GET /v1/refunds/{id}` + `GET /v1/invoices/{id}`) и обнаружением возвратов из кабинета Paymos через `RefundDiscovery`.
- **Штатный хук возврата Blesta (`Paymos::refund()`):** отправляет запрос `full`-возврата через `RefundCoordinator`. Так как возвраты в Paymos проводятся асинхронно, пока операция находится в статусе `pending`, `processing` или `unknown`, метод `Paymos::refund()` возвращает `null` с информационным уведомлением — без преждевременной проводки списания. Когда Paymos подтверждает `completed` (по вебхуку `withdrawal.completed` или через сверку по cron), `RefundAdapter::applyCompleted()` один раз фиксирует полный возврат через `Transactions::edit` либо добавляет заметку о возврате переплаты, оставляя исходный счёт оплаченным.

## Поддерживаемые локали

В пакет включены переводы для всех 6 локалей Blesta:
`en_us`, `ru_ru`, `de_de`, `es_es`, `tr_tr`, `zh_cn`.

## Ссылки

- [Документация](https://paymos.io/ru/docs/cms-blesta)
- [Исходный код](https://github.com/Paymos-labs/blesta)
- [История изменений](CHANGELOG.md)
- [Поддержка](mailto:support@paymos.io)
- Лицензия: [MIT](LICENSE)

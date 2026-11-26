# Paymos para Blesta

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Pasarela de pago oficial no comercial (non-merchant) de Paymos y plugin complementario de conciliación para **Blesta v5 y v6**.

Acepte cuatro stablecoins (**USDT**, **USDC**, **USD1**, **DAI**) y **XAUT** respaldado por oro a través del checkout alojado de Paymos, con **Conectar Paymos** en un clic, almacenamiento cifrado de credenciales AES-256-GCM, creación de facturas resistente a fallos con conceptos (`products[]`), webhooks firmados multientorno, verificación inversa, conciliación programada de 24 horas para facturas y reembolsos, y reembolsos `full` y `overpayment` calculados en servidor.

[![Ver vídeo: 12 plugins oficiales de CMS para pagos cripto — Conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Demostración en vídeo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- Blesta v5 o v6
- PHP 8.1+ con las extensiones `curl`, `hash`, `json`, `openssl` y `pdo`
- Una URL de instalación de Blesta con **HTTPS** (Conectar Paymos rechaza HTTP sin cifrar)
- Una cuenta de Paymos con el proyecto destino seleccionado en el panel de Paymos

No hace falta ejecutar Composer durante la instalación: `paymos/php-sdk` viene incluido en el paquete de lanzamiento dentro de `components/gateways/nonmerchant/paymos/vendor/paymos/php-sdk/`.

## Instalación y conexión

1. Descargue el paquete de lanzamiento más reciente desde [GitHub Releases](https://github.com/Paymos-labs/blesta/releases/latest).
2. Extraiga el archivo en la raíz web de Blesta. Instalará dos componentes:
   - `components/gateways/nonmerchant/paymos/` — la pasarela de pago no comercial de Paymos;
   - `plugins/paymos/` — el plugin complementario de Blesta que aporta conciliación cron cada 10 minutos (`Reconciler` + `RefundAdapter::run()`) y controladores de personal protegidos con CSRF (`AdminConnect`, `AdminRefunds`).
3. En la administración de Blesta, instale ambos componentes:
   - **Settings → Company → Payment Gateways → Available → Paymos → Install**
   - **Settings → Company → Plugins → Available → Paymos Companion → Install**
4. Abra en el panel de Paymos el proyecto en el que desea facturar.
5. Abra **Settings → Company → Payment Gateways → Paymos → Manage** en Blesta y haga clic en **Conectar Paymos** (Connect Paymos).
6. Apruebe la conexión en la pestaña de Paymos tras verificar la URL de la instalación y el código de usuario.

Esa única aprobación aprovisiona las credenciales de la Payment API para **Sandbox** y **Producción** (`pk_*` / `sk_*` / `prj_*` / `whsec_*`, además de la capacidad de reembolso `invoice_refunds_v1`) y registra automáticamente el callback del webhook:

```text
https://your-blesta-domain/callback/gw/1/paymos/
```

### Nota sobre instalaciones multiempresa

La conexión en un clic vincula la ruta canónica de callback `/callback/gw/1/paymos/` para la empresa principal de Blesta (`company_id = 1`). Las instalaciones multiempresa de Blesta que operan bajo `company_id != 1` no se aprovisionan mediante One-Click Connect.

## Ajustes y almacenamiento de secretos

Tras la conexión, la pantalla de configuración de la pasarela muestra únicamente opciones no secretas:

- **Conexión (Connection):** estado de solo lectura (`Connected` / `Not connected`) y el botón **Conectar Paymos** / **Reconectar Paymos**.
- **Modo (Mode):** `Sandbox (Test)` o `Live (Mainnet)`.
- **Texto del botón (Button Text):** etiqueta que se muestra en el botón de pago de la factura de Blesta.
- **URL de callback del webhook (Webhook Callback URL):** referencia de solo lectura que muestra `/callback/gw/1/paymos/`.

No existe ningún campo manual para API Key, API Secret, Project ID ni Webhook Secret en la interfaz ni en los metadatos de la pasarela. Todas las credenciales y el estado temporal de dispositivo RFC 8628 se sellan en un sobre autenticado `AES-256-GCM` (`AesGcmEnvelope`) derivado vía HKDF de `Configure::get('Blesta.system_key')`, se guardan en `paymos_settings` y nunca se muestran en HTML ni en logs.

## Flujo de pago, fiabilidad y conciliación cron

- **Checkout resistente a fallos y `products[]`:** antes de enviar `POST /v1/invoices`, `CheckoutService` guarda una fila de intención `STATUS_CREATING`. Si el proceso se interrumpe antes de guardar la respuesta, el siguiente renderizado repite exactamente el mismo `external_order_id`, `project_id`, `amount`, `currency` y `environment` sin crear una factura duplicada. Se envían hasta 50 conceptos válidos de la factura de Blesta en `products[]` (pasando limpiamente a solo importe cuando no son válidos o no están disponibles). Nunca se envía `merchant_id` ni TTL desde el cliente.
- **Sustitución fail-closed de facturas:** cuando cambia el importe, la moneda, el modo o el proyecto de una factura — o cuando una factura anterior ha vencido sin pagarse (`InvoiceRenewal`) — `CheckoutService` cierra la factura previa mediante `InvoiceReplacement::close()` antes de crear un reemplazo. Si la factura anterior sigue abierta, ya está pagada, marcada como `paid_late`, resulta inalcanzable o devuelve 404, se bloquea el reemplazo (`InvoiceReplacementBlockedException`) y se marca para revisión manual.
- **Webhooks firmados multientorno:** `CallbackProcessor` verifica `X-Webhook-Signature` sin distinguir mayúsculas en Sandbox y Producción con `MultiEnvironmentWebhookVerifier` (periodo de gracia de rotación dual `v1=`, tolerancia de desfase de reloj de 300 segundos y rechazo si coinciden ambos entornos). La deduplicación atómica con control de propietario devuelve `200` en duplicados confirmados, `409` en entregas concurrentes en curso y `401` en firmas inválidas o vencidas. Los eventos terminales pasan por `InvoiceReverseVerifier` (`GET /v1/invoices/{id}`), protecciones frente a retroceso y `AmountGuard` (`HTTP 400` + `manual_review` en caso de discrepancia). `invoice.paid_late` se registra como nota con la marca `paid_late_noted` sin alterar el estado del pago.
- **Red de seguridad cron programada:** la tarea cron cada 10 minutos del plugin complementario (`paymos_reconcile`) revisa hasta 50 facturas impagadas de Paymos de las últimas 24 horas mediante `InvoiceReconciler` (limitada a 600 segundos) y concilia los reembolsos pendientes y los iniciados desde el panel.

## Reembolsos (`full` y `overpayment`)

Paymos admite reembolsos **completos** (`full`) y por **sobrepago** (`overpayment`, en facturas `paid_over`) calculados en servidor, enviados como transferencias salientes en blockchain estrictamente a la dirección verificada `first_sender`. Los importes parciales arbitrarios y los campos de destinatario no gestionados (`destination_address`, `network`, `token`, `crypto_amount`) se rechazan antes de cualquier llamada a la API de Paymos.

- **Panel de administración de reembolsos de Paymos (`/admin/plugin/paymos/admin_refunds/`):** interfaz exclusiva para personal y protegida con CSRF que soporta reembolsos `full` y (para facturas `paid_over`) `overpayment` con idempotencia por `request_token` UUIDv4 persistente, verificación mediante `RefundCoordinator` (`GET /v1/refunds/{id}` + `GET /v1/invoices/{id}`) y `RefundDiscovery` para reembolsos iniciados directamente en el panel de Paymos.
- **Hook nativo de reembolso de Blesta (`Paymos::refund()`):** envía un reembolso `full` mediante `RefundCoordinator`. Dado que los reembolsos de Paymos se liquidan de forma asíncrona, `Paymos::refund()` devuelve `null` con un aviso informativo mientras el reembolso está en estado `pending`, `processing` o `unknown`, sin contabilizar jamás un cargo anticipado. Cuando Paymos confirma `completed` (por webhook `withdrawal.completed` o conciliación cron), `RefundAdapter::applyCompleted()` proyecta el reembolso una sola vez vía `Transactions::edit` para reembolsos `full`, o registra una nota de sobrepago manteniendo la factura original como pagada para reembolsos `overpayment`.

## Idiomas admitidos

Se incluyen traducciones nativas empaquetadas para los 6 idiomas de Blesta:
`en_us`, `ru_ru`, `de_de`, `es_es`, `tr_tr`, `zh_cn`.

## Enlaces

- [Documentación](https://paymos.io/es/docs/cms-blesta)
- [Código fuente](https://github.com/Paymos-labs/blesta)
- [Historial de cambios](CHANGELOG.md)
- [Soporte](mailto:support@paymos.io)
- Licencia: [MIT](LICENSE)

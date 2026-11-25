<?php

$lang['Paymos.name'] = 'Paymos';
$lang['Paymos.description'] = 'Acepta USDT, USDC, USD1, DAI y XAUT respaldado en oro en Blesta mediante Paymos Checkout con webhooks firmados y reembolsos totales o de sobrepago.';

$lang['Paymos.connection'] = 'Conexión';
$lang['Paymos.connection.connected'] = 'Conectado — las credenciales de Sandbox y Producción están cifradas en esta instalación de Blesta.';
$lang['Paymos.connection.disconnected'] = 'No conectado — haz clic en «Conectar Paymos» y aprueba esta instalación de Blesta.';
$lang['Paymos.connection.note'] = 'La conexión en un clic aprovisiona Sandbox y Producción a la vez y registra tu webhook automáticamente.';

$lang['Paymos.connect.button'] = 'Conectar Paymos';
$lang['Paymos.connect.reconnect'] = 'Reconectar Paymos';
$lang['Paymos.connect.starting'] = 'Iniciando conexión…';
$lang['Paymos.connect.waiting'] = 'Esperando autorización en Paymos.';
$lang['Paymos.connect.open'] = 'Abrir la página de autorización';
$lang['Paymos.connect.blocked'] = 'El navegador bloqueó la pestaña de autorización.';
$lang['Paymos.connect.code'] = 'Código:';
$lang['Paymos.connect.failed'] = 'No se pudo conectar Paymos. Verifica HTTPS e inténtalo de nuevo.';
$lang['Paymos.connect.https_required'] = 'Blesta debe servirse mediante HTTPS antes de conectar Paymos.';

$lang['Paymos.mode'] = 'Modo';
$lang['Paymos.mode.sandbox'] = 'Sandbox (Pruebas)';
$lang['Paymos.mode.live'] = 'Producción (Live)';
$lang['Paymos.mode_note'] = 'Usa Sandbox para pagos de prueba y cambia a Producción cuando estés listo para cobrar facturas reales.';

$lang['Paymos.button_text'] = 'Texto del botón';
$lang['Paymos.button_text_note'] = 'Etiqueta que se muestra en el botón de pago de la factura en Blesta.';
$lang['Paymos.button_text_default'] = 'Pagar con Paymos';

$lang['Paymos.webhook_url'] = 'URL de callback del webhook';
$lang['Paymos.webhook_url_note'] = 'Registrada automáticamente por Conectar Paymos para la empresa 1 (/callback/gw/1/paymos/).';

$lang['Paymos.buildprocess.submit'] = 'Pagar con Paymos';
$lang['Paymos.buildprocess.note'] = 'Serás redirigido a Paymos Checkout para completar el pago con USDT, USDC, USD1, DAI o XAUT.';

$lang['Paymos.refunds.title'] = 'Reembolsos de Paymos';
$lang['Paymos.refunds.operation'] = 'Operación';
$lang['Paymos.refunds.type'] = 'Tipo';
$lang['Paymos.refunds.cash_status'] = 'Estado del envío';
$lang['Paymos.refunds.blesta_record'] = 'Registro en Blesta';
$lang['Paymos.refunds.review_reason'] = 'Motivo de revisión';
$lang['Paymos.refunds.amount'] = 'Importe';
$lang['Paymos.refunds.button_full'] = 'Reembolsar todo';
$lang['Paymos.refunds.button_overpayment'] = 'Reembolsar sobrepago';
$lang['Paymos.refunds.external_badge'] = 'Reembolso iniciado desde el panel de Paymos';
$lang['Paymos.refunds.empty'] = 'Todavía no hay reembolsos de Paymos registrados para esta factura.';
$lang['Paymos.refunds.reconnect_hint'] = 'Los reembolsos no están habilitados en esta conexión. Haz clic en «Reconectar Paymos» en los ajustes de la pasarela.';
$lang['Paymos.refunds.native_in_progress'] = 'El reembolso de Paymos está en curso. Blesta marcará la transacción como reembolsada automáticamente cuando Paymos confirme su finalización; sigue el progreso en «Reembolsos de Paymos».';
$lang['Paymos.refunds.native_failed'] = 'No se pudo completar el reembolso de Paymos. Revisa los detalles en «Reembolsos de Paymos».';

$lang['Paymos.!error.mode.valid'] = 'Selecciona un modo válido: Sandbox o Producción.';
$lang['Paymos.!error.button_text.length'] = 'El texto del botón debe tener 100 caracteres o menos.';
$lang['Paymos.!error.not_connected'] = 'Paymos aún no está conectado. Abre los ajustes de la pasarela y haz clic en «Conectar Paymos».';
$lang['Paymos.!error.checkout.failed'] = 'Paymos no está disponible temporalmente. Inténtalo de nuevo o contacta con soporte.';
$lang['Paymos.!error.payment_cannot_be_used'] = 'Este método de pago no se puede utilizar para esta factura.';
$lang['Paymos.!error.replacement_blocked'] = 'Esta factura requiere revisión antes de continuar con el pago. Contacta con soporte.';
$lang['Paymos.!error.webhook.invalid'] = 'Error en la verificación del webhook de Paymos.';
$lang['Paymos.!error.refund.partial_unsupported'] = 'No se admiten reembolsos parciales. Paymos solo admite reembolsos totales y de sobrepago calculados por el servidor.';
$lang['Paymos.!error.refund.failed'] = 'No se pudo enviar la solicitud de reembolso a Paymos.';
$lang['Paymos.!error.refund.access_denied'] = 'Se requiere autorización de administrador para gestionar los reembolsos de Paymos.';

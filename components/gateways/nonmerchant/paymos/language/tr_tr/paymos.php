<?php

$lang['Paymos.name'] = 'Paymos';
$lang['Paymos.description'] = 'Blesta’da imzalı webhook’lar, tam iade ve fazla ödeme iadesiyle Paymos Checkout üzerinden USDT, USDC, USD1, DAI ve altına dayalı XAUT kabul edin.';

$lang['Paymos.connection'] = 'Bağlantı';
$lang['Paymos.connection.connected'] = 'Bağlı — Sandbox ve Canlı kimlik bilgileri bu Blesta kurulumunda şifrelenerek saklanıyor.';
$lang['Paymos.connection.disconnected'] = 'Bağlı değil — “Paymos’u bağla” düğmesine tıklayın ve bu Blesta kurulumunu onaylayın.';
$lang['Paymos.connection.note'] = 'Tek tıkla bağlantı hem Sandbox hem Canlı kimlik bilgilerini oluşturur ve webhook adresinizi otomatik kaydeder.';

$lang['Paymos.connect.button'] = 'Paymos’u bağla';
$lang['Paymos.connect.reconnect'] = 'Paymos’u yeniden bağla';
$lang['Paymos.connect.starting'] = 'Bağlantı başlatılıyor…';
$lang['Paymos.connect.waiting'] = 'Paymos’ta onay bekleniyor.';
$lang['Paymos.connect.open'] = 'Onay sayfasını aç';
$lang['Paymos.connect.blocked'] = 'Tarayıcınız onay sekmesini engelledi.';
$lang['Paymos.connect.code'] = 'Kod:';
$lang['Paymos.connect.failed'] = 'Paymos bağlanamadı. HTTPS ayarını kontrol edip tekrar deneyin.';
$lang['Paymos.connect.https_required'] = 'Paymos’u bağlamadan önce Blesta’nın HTTPS üzerinden çalışması gerekir.';

$lang['Paymos.mode'] = 'Mod';
$lang['Paymos.mode.sandbox'] = 'Sandbox (Test)';
$lang['Paymos.mode.live'] = 'Canlı (Mainnet)';
$lang['Paymos.mode_note'] = 'Test ödemeleri için Sandbox modunu kullanın, gerçek faturaları tahsil etmeye hazır olduğunuzda Canlı moda geçin.';

$lang['Paymos.button_text'] = 'Düğme metni';
$lang['Paymos.button_text_note'] = 'Blesta fatura ödeme düğmesinde gösterilen metin.';
$lang['Paymos.button_text_default'] = 'Paymos ile öde';

$lang['Paymos.webhook_url'] = 'Webhook Geri Çağrı URL’si';
$lang['Paymos.webhook_url_note'] = 'Şirket 1 (/callback/gw/1/paymos/) için Paymos bağlantısı tarafından otomatik kaydedilir.';

$lang['Paymos.buildprocess.submit'] = 'Paymos ile öde';
$lang['Paymos.buildprocess.note'] = 'Ödemeyi USDT, USDC, USD1, DAI veya XAUT ile tamamlamak için Paymos Checkout sayfasına yönlendirileceksiniz.';

$lang['Paymos.refunds.title'] = 'Paymos İadeleri';
$lang['Paymos.refunds.operation'] = 'İşlem';
$lang['Paymos.refunds.type'] = 'Tür';
$lang['Paymos.refunds.cash_status'] = 'Ödeme durumu';
$lang['Paymos.refunds.blesta_record'] = 'Blesta kaydı';
$lang['Paymos.refunds.review_reason'] = 'İnceleme nedeni';
$lang['Paymos.refunds.amount'] = 'Tutar';
$lang['Paymos.refunds.button_full'] = 'Tamamını iade et';
$lang['Paymos.refunds.button_overpayment'] = 'Fazla ödemeyi iade et';
$lang['Paymos.refunds.external_badge'] = 'Paymos panelinden başlatılan iade';
$lang['Paymos.refunds.empty'] = 'Bu fatura için henüz kayıtlı Paymos iadesi yok.';
$lang['Paymos.refunds.reconnect_hint'] = 'Bu bağlantıda iade etkin değil. Ağ geçidi ayarlarında “Paymos’u yeniden bağla” düğmesine tıklayın.';
$lang['Paymos.refunds.native_in_progress'] = 'Paymos iadesi devam ediyor. Paymos tamamlandığını doğruladığında Blesta işlemi otomatik olarak iade edildi işaretler; durumu “Paymos İadeleri” bölümünden takip edin.';
$lang['Paymos.refunds.native_failed'] = 'Paymos iadesi tamamlanamadı. Ayrıntılar için “Paymos İadeleri” bölümünü kontrol edin.';

$lang['Paymos.!error.mode.valid'] = 'Geçerli bir mod seçin: Sandbox veya Canlı.';
$lang['Paymos.!error.button_text.length'] = 'Düğme metni en fazla 100 karakter olabilir.';
$lang['Paymos.!error.not_connected'] = 'Paymos henüz bağlı değil. Ağ geçidi ayarlarını açın ve “Paymos’u bağla” düğmesine tıklayın.';
$lang['Paymos.!error.checkout.failed'] = 'Paymos geçici olarak kullanılamıyor. Lütfen tekrar deneyin veya destekle iletişime geçin.';
$lang['Paymos.!error.payment_cannot_be_used'] = 'Bu ödeme yöntemi bu fatura için kullanılamaz.';
$lang['Paymos.!error.replacement_blocked'] = 'Ödemeye devam etmeden önce bu faturanın incelenmesi gerekiyor. Lütfen destekle iletişime geçin.';
$lang['Paymos.!error.webhook.invalid'] = 'Paymos webhook doğrulaması başarısız oldu.';
$lang['Paymos.!error.refund.partial_unsupported'] = 'Kısmi iadeler desteklenmez. Paymos yalnızca sunucu tarafından hesaplanan tam iade ve fazla ödeme iadesini destekler.';
$lang['Paymos.!error.refund.failed'] = 'İade isteği Paymos’a gönderilemedi.';
$lang['Paymos.!error.refund.access_denied'] = 'Paymos iadelerini yönetmek için yönetici yetkisi gerekir.';

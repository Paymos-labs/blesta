# Blesta için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

**Blesta v5 ve v6** için resmi Paymos non-merchant ödeme ağ geçidi ve eşlikçi mutabakat eklentisidir.

Tek Tıkla **Paymos’u bağla**, AES-256-GCM şifreli kimlik bilgisi saklama, fatura kalemleriyle (`products[]`) çökmeye dayanıklı fatura oluşturma, imzalı çoklu ortam webhook’ları, ters doğrulama, zamanlanmış 24 saatlik fatura ve iade mutabakatı ile sunucu tarafından hesaplanan `full` ve `overpayment` iadeleri eşliğinde, Paymos barındırılan ödeme sayfası üzerinden dört stablecoin (**USDT**, **USDC**, **USD1**, **DAI**) ve altın destekli **XAUT** kabul edin.

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video tanıtımı (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- Blesta v5 veya v6
- `curl`, `hash`, `json`, `openssl` ve `pdo` uzantıları etkin PHP 8.1+
- **HTTPS** üzerinden çalışan bir Blesta kurulum URL’si (Paymos’u bağla düz HTTP’yi reddeder)
- Paymos panelinde hedef projenin seçili olduğu bir Paymos hesabı

Kurulum sırasında Composer adımı gerekmez: `paymos/php-sdk` sürüm arşivinin içinde `components/gateways/nonmerchant/paymos/vendor/paymos/php-sdk/` dizininde hazır olarak gelir.

## Kurulum ve bağlantı

1. En güncel sürüm paketini [GitHub Releases](https://github.com/Paymos-labs/blesta/releases/latest) sayfasından indirin.
2. Arşivi Blesta web kök dizininize çıkarın. Şu iki bileşeni kurar:
   - `components/gateways/nonmerchant/paymos/` — Paymos non-merchant ödeme ağ geçidi;
   - `plugins/paymos/` — 10 dakikalık cron mutabakatı (`Reconciler` + `RefundAdapter::run()`) ve CSRF korumalı personel denetleyicileri (`AdminConnect`, `AdminRefunds`) sağlayan eşlikçi Blesta eklentisi.
3. Blesta yönetim panelinde her iki bileşeni de kurun:
   - **Settings → Company → Payment Gateways → Available → Paymos → Install**
   - **Settings → Company → Plugins → Available → Paymos Companion → Install**
4. Faturaların aktarılacağı projeyi Paymos panelinde açın.
5. Blesta’da **Settings → Company → Payment Gateways → Paymos → Manage** sayfasını açın ve **Paymos’u bağla** (Connect Paymos) düğmesine tıklayın.
6. Kurulum URL’sini ve kullanıcı kodunu doğruladıktan sonra Paymos sekmesinde bağlantıyı onaylayın.

Bu tek onay hem **Sandbox** hem de **Canlı** (Live) Payment API kimlik bilgilerini (`pk_*` / `sk_*` / `prj_*` / `whsec_*` ve `invoice_refunds_v1` iade yetkisini) yapılandırır ve webhook callback adresini otomatik olarak kaydeder:

```text
https://your-blesta-domain/callback/gw/1/paymos/
```

### Çoklu Şirket Notu

Tek Tıkla Bağlantı (One-Click Connect), Blesta’nın birincil şirketi (`company_id = 1`) için standart `/callback/gw/1/paymos/` callback yolunu bağlar. `company_id != 1` altında çalışan çoklu şirketli Blesta kurulumları One-Click Connect ile yapılandırılmaz.

## Ayarlar ve gizli bilgilerin saklanması

Bağlantıdan sonra ağ geçidi ayarları ekranı yalnızca gizli olmayan seçenekleri gösterir:

- **Bağlantı (Connection):** salt okunur durum (`Connected` / `Not connected`) ve **Paymos’u bağla** / **Paymos’u yeniden bağla** düğmesi.
- **Mod (Mode):** `Sandbox (Test)` veya `Live (Mainnet)`.
- **Düğme metni (Button Text):** Blesta fatura ödeme düğmesinde gösterilen etiket.
- **Webhook Callback URL’si:** `/callback/gw/1/paymos/` yolunu gösteren salt okunur referans.

Ağ geçidi arayüzünde veya meta verilerinde elle girilen API Key, API Secret, Project ID veya Webhook Secret alanı yoktur. Tüm kimlik bilgileri ve geçici RFC 8628 cihaz durumu, `Configure::get('Blesta.system_key')` değerinden HKDF ile türetilen kimlik doğrulamalı bir `AES-256-GCM` zarfı (`AesGcmEnvelope`) içinde mühürlenir, `paymos_settings` tablosunda saklanır ve hiçbir zaman HTML veya günlüklere geri yazdırılmaz.

## Ödeme akışı, güvenilirlik ve cron mutabakatı

- **Çökmeye dayanıklı ödeme ve `products[]`:** `CheckoutService`, `POST /v1/invoices` isteğini göndermeden önce bir `STATUS_CREATING` niyet satırı kaydeder. Yanıt kaydedilmeden önce işlem kesilirse, sonraki yükleme mükerrer fatura oluşturmadan tam olarak aynı `external_order_id`, `project_id`, `amount`, `currency` ve `environment` değerlerini yineler. Geçerli en fazla 50 Blesta fatura kalemi `products[]` içinde iletilir (geçersiz veya mevcut değilse yalnızca tutar bazlı ödemeye sorunsuz geçiş yapılır). Hiçbir zaman `merchant_id` veya istemci taraflı TTL gönderilmez.
- **Güvenli durdurmalı (fail-closed) fatura yenileme:** Bir fatura tutarı, para birimi, modu veya projesi değiştiğinde — ya da önceki bir fatura ödenmeden kapandığında (`InvoiceRenewal`) — `CheckoutService` yeni fatura oluşturmadan önce `InvoiceReplacement::close()` ile eski faturayı kapatır. Eski fatura hâlâ açıksa, ödenmişse, `paid_late` olarak işaretlenmişse, erişilemiyorsa veya 404 döndürüyorsa yenileme engellenir (`InvoiceReplacementBlockedException`) ve manuel inceleme için işaretlenir.
- **İmzalı çoklu ortam webhook’ları:** `CallbackProcessor`, `MultiEnvironmentWebhookVerifier` aracılığıyla Sandbox ve Canlı ortamlar arasında büyük/küçük harfe duyarsız biçimde `X-Webhook-Signature` doğrulaması yapar (çift `v1=` rotasyon toleransı, 300 saniye saat farkı toleransı, her iki ortam da eşleşirse ret). Sahip-kilitli atomik tekilleştirme işlenmiş yinelemeler için `200`, eşzamanlı süren teslimatlar için `409`, geçersiz veya süresi dolmuş imzalar için `401` döndürür. Nihai olaylar `InvoiceReverseVerifier` (`GET /v1/invoices/{id}`), geri alma korumaları ve `AmountGuard` (uyuşmazlıkta `HTTP 400` + `manual_review`) aşamalarından geçer. `invoice.paid_late`, ödeme durumunu değiştirmeden bir not ve `paid_late_noted` bayrağı olarak kaydedilir.
- **Zamanlanmış cron güvenlik ağı:** Eşlikçi eklentinin 10 dakikalık cron görevi (`paymos_reconcile`), `InvoiceReconciler` aracılığıyla son 24 saate ait en fazla 50 ödenmemiş Paymos faturasını tarar (600 saniye hız sınırlamalı) ve bekleyen ya da Paymos panelinden başlatılan iadeleri mutabık kılar.

## İadeler (`full` ve `overpayment`)

Paymos, giden blokzincir transferleri olarak kesinlikle doğrulanmış `first_sender` adresine gönderilen sunucu hesaplamalı **tam** (`full`) ve **fazla ödeme** (`overpayment`, `paid_over` faturalar için) iadelerini destekler. Keyfi kısmi tutarlar ve yönetilmeyen alıcı alanları (`destination_address`, `network`, `token`, `crypto_amount`) herhangi bir Paymos API çağrısından önce reddedilir.

- **Paymos İadeleri Yönetici Paneli (`/admin/plugin/paymos/admin_refunds/`):** Yalnızca personel erişimine açık, CSRF korumalı arayüz; kalıcı UUIDv4 `request_token` idempotency korumasıyla hem `full` hem de (`paid_over` faturalar için) `overpayment` iadelerini, `RefundCoordinator` doğrulamasını (`GET /v1/refunds/{id}` + `GET /v1/invoices/{id}`) ve doğrudan Paymos panelinden başlatılan iadeler için `RefundDiscovery` keşfini destekler.
- **Yerleşik Blesta İade Kancası (`Paymos::refund()`):** `RefundCoordinator` üzerinden bir `full` iadesi gönderir. Paymos iadeleri asenkron olarak sonuçlandığından, iade `pending`, `processing` veya `unknown` durumundayken `Paymos::refund()` bilgilendirici bir mesajla birlikte `null` döndürür — asla erken muhasebe borcu yazmaz. Paymos `completed` durumunu onayladığında (`withdrawal.completed` webhook’u veya cron mutabakatı ile), `RefundAdapter::applyCompleted()` `full` iadeler için `Transactions::edit` üzerinden iadeyi tam bir kez işler; `overpayment` iadeler içinse orijinal faturayı ödenmiş tutarak bir fazla ödeme notu kaydeder.

## Desteklenen diller

Her 6 Blesta dili için yerleşik çeviriler pakete dahildir:
`en_us`, `ru_ru`, `de_de`, `es_es`, `tr_tr`, `zh_cn`.

## Bağlantılar

- [Dokümantasyon](https://paymos.io/tr/docs/cms-blesta)
- [Kaynak kodu](https://github.com/Paymos-labs/blesta)
- [Değişiklik günlüğü](CHANGELOG.md)
- [Destek](mailto:support@paymos.io)
- Lisans: [MIT](LICENSE)

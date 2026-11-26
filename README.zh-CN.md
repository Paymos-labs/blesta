# Paymos Blesta 官方稳定币支付网关

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

适用于 **Blesta v5 和 v6** 的 Paymos 官方非商户托管（Non-Merchant）支付网关及配套对账插件。

通过 Paymos 托管收银台接收四种稳定币（**USDT**、**USDC**、**USD1**、**DAI**）及黄金锚定代币 **XAUT** 收款，支持一键**连接 Paymos**、AES-256-GCM 凭证加密存储、含明细项（`products[]`）的防崩溃账单创建、多环境 HMAC-SHA256 签名验证 Webhook、反向校验、24 小时定时账单与退款对账，以及由服务端计算的 `full` 与 `overpayment` 退款。

[![观看演示：12 款官方 CMS 稳定币支付插件 — 一键连接 (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，支持 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- Blesta v5 或 v6
- PHP 8.1+，启用 `curl`、`hash`、`json`、`openssl` 和 `pdo` 扩展
- 使用 **HTTPS** 的 Blesta 站点地址（一键连接会拒绝明文 HTTP）
- 已注册 Paymos 账户，并在 Paymos 控制台中选中目标项目

安装时无需执行 Composer 步骤：`paymos/php-sdk` 已内置在发行压缩包的 `components/gateways/nonmerchant/paymos/vendor/paymos/php-sdk/` 目录中。

## 安装与一键连接

1. 前往 [GitHub Releases](https://github.com/Paymos-labs/blesta/releases/latest) 下载最新发行包。
2. 将压缩包解压到 Blesta 网站根目录，它会自动安装以下两个组件：
   - `components/gateways/nonmerchant/paymos/` — Paymos 非商户托管支付网关；
   - `plugins/paymos/` — 配套 Blesta 插件，提供每 10 分钟一次的定时任务对账（`Reconciler` + `RefundAdapter::run()`）以及受 CSRF 保护的管理员控制器（`AdminConnect`、`AdminRefunds`）。
3. 在 Blesta 后台安装两个组件：
   - **Settings → Company → Payment Gateways → Available → Paymos → Install**
   - **Settings → Company → Plugins → Available → Paymos Companion → Install**
4. 在 Paymos 控制台中打开需要接收账单的项目。
5. 在 Blesta 中打开 **Settings → Company → Payment Gateways → Paymos → Manage**，点击**连接 Paymos**（Connect Paymos）。
6. 核对站点地址与用户验证码后，在 Paymos 标签页中批准连接。

这一次批准即可同时配置**沙盒**（Sandbox）与**正式模式**（Live）的 Payment API 凭证（`pk_*` / `sk_*` / `prj_*` / `whsec_*` 以及 `invoice_refunds_v1` 退款权限），并自动注册 Webhook 回调地址：

```text
https://your-blesta-domain/callback/gw/1/paymos/
```

### 多公司架构说明

一键连接（One-Click Connect）会将标准回调路径 `/callback/gw/1/paymos/` 绑定至 Blesta 的主公司（`company_id = 1`）。运行在 `company_id != 1` 下的多公司 Blesta 站点暂不支持通过一键连接自动配置。

## 设置与密钥安全存储

连接完成后，网关设置页仅展示非敏感选项：

- **连接（Connection）：** 只读状态（`Connected` / `Not connected`）以及**连接 Paymos** / **重新连接 Paymos**按钮。
- **模式（Mode）：** `Sandbox (Test)` 或 `Live (Mainnet)`。
- **按钮文字（Button Text）：** 显示在 Blesta 账单付款按钮上的文字。
- **Webhook 回调地址（Webhook Callback URL）：** 显示 `/callback/gw/1/paymos/` 的只读参考项。

网关界面和元数据中没有任何手动输入 API Key、API Secret、Project ID 或 Webhook Secret 的字段。所有凭证与临时 RFC 8628 设备状态均封装在经认证加密的 `AES-256-GCM` 信封（`AesGcmEnvelope`）中，加密密钥通过 HKDF 派生自 `Configure::get('Blesta.system_key')`，存储于 `paymos_settings` 表中，绝不会回显至 HTML 或写入日志。

## 支付流程、可靠性与定时对账

- **防崩溃结账与明细项（`products[]`）：** 在发送 `POST /v1/invoices` 之前，`CheckoutService` 会先持久化一条 `STATUS_CREATING` 意图记录。如果进程在保存响应前中断，下次渲染会重放完全相同的 `external_order_id`、`project_id`、`amount`、`currency` 与 `environment`，不会产生重复账单。系统最多将 50 条有效的 Blesta 账单明细写入 `products[]`（明细无效或不可用时平滑回退为纯金额结账），且绝不发送 `merchant_id` 或客户端 TTL。
- **安全阻断式账单替换（Fail-Closed）：** 当账单金额、币种、模式或项目发生变化，或旧账单在未付款状态下结束（`InvoiceRenewal`）时，`CheckoutService` 会先通过 `InvoiceReplacement::close()` 关闭原账单，再创建替换账单。如果旧账单仍处于开放状态、已付款、已标记 `paid_late`、无法访问或返回 404，替换会被阻断（`InvoiceReplacementBlockedException`）并标记供人工审核。
- **多环境签名 Webhook：** `CallbackProcessor` 通过 `MultiEnvironmentWebhookVerifier` 跨沙盒与正式环境大小写不敏感地验证 `X-Webhook-Signature`（支持双 `v1=` 密钥轮换宽限期、300 秒时钟偏移容差；若两套环境同时匹配则拒绝）。基于归属围栏的原子去重对已提交重复事件返回 `200`，对并发处理中的投递返回 `409`，对无效或过期签名返回 `401`。终态事件依次通过 `InvoiceReverseVerifier`（`GET /v1/invoices/{id}`）、防回滚保护及 `AmountGuard`（金额不符返回 `HTTP 400` 并标记 `manual_review`）。`invoice.paid_late` 会记录为备注并打上 `paid_late_noted` 标记，不修改付款状态。
- **定时任务安全兜底：** 配套插件每 10 分钟执行一次定时任务（`paymos_reconcile`），通过 `InvoiceReconciler` 扫描过去 24 小时内最多 50 笔未付 Paymos 账单（限频 600 秒），并同步对账处理中的退款及 Paymos 控制台发起的退款。

## 退款（`full` 与 `overpayment`）

Paymos 支持由服务端计算的**全额退款**（`full`）与**多付退款**（`overpayment`，针对 `paid_over` 账单），退款作为链上转出交易严格发送至原始入账校验的 `first_sender` 地址。任何自定义部分金额以及未受控的收款参数（`destination_address`、`network`、`token`、`crypto_amount`）都会在调用 Paymos API 前被直接拦截。

- **Paymos 退款管理面板（`/admin/plugin/paymos/admin_refunds/`）：** 仅限后台员工访问并受 CSRF 保护的界面，支持 `full` 及（针对 `paid_over` 账单的）`overpayment` 退款，配备持久化 UUIDv4 `request_token` 幂等保障、`RefundCoordinator` 双端核验（`GET /v1/refunds/{id}` + `GET /v1/invoices/{id}`），以及针对 Paymos 控制台直接发起退款的 `RefundDiscovery` 自动发现。
- **Blesta 原生退款钩子（`Paymos::refund()`）：** 通过 `RefundCoordinator` 提交 `full` 退款。由于 Paymos 退款为异步链上结算，在退款处于 `pending`、`processing` 或 `unknown` 状态时，`Paymos::refund()` 会返回 `null` 并附带提示信息——绝不提前记入账务扣减。待 Paymos 确认退款 `completed`（经 `withdrawal.completed` Webhook 或定时任务对账确认）后，`RefundAdapter::applyCompleted()` 会精准执行一次回写：对 `full` 退款通过 `Transactions::edit` 完成记录，对 `overpayment` 退款则记录多付退还备注并保持原账单为已付款状态。

## 支持的语言

安装包内置全部 6 种 Blesta 本地化语言包：
`en_us`、`ru_ru`、`de_de`、`es_es`、`tr_tr`、`zh_cn`。

## 相关链接

- [开发文档](https://paymos.io/zh-hans/docs/cms-blesta)
- [源码仓库](https://github.com/Paymos-labs/blesta)
- [更新日志](CHANGELOG.md)
- [技术支持](mailto:support@paymos.io)
- 许可证：[MIT](LICENSE)

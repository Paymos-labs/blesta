<?php

$lang['Paymos.name'] = 'Paymos';
$lang['Paymos.description'] = '在 Blesta 中通过 Paymos 托管收银台接受 USDT、USDC、USD1、DAI 稳定币及黄金支持的 XAUT，支持签名 Webhook 与全额或多付款退款。';

$lang['Paymos.connection'] = '连接状态';
$lang['Paymos.connection.connected'] = '已连接 — 沙盒与正式环境密钥已在此 Blesta 安装中加密保存。';
$lang['Paymos.connection.disconnected'] = '未连接 — 请点击「连接 Paymos」并批准此 Blesta 安装。';
$lang['Paymos.connection.note'] = '一键连接会同时配置沙盒与正式环境凭证，并自动注册 Webhook 回调地址。';

$lang['Paymos.connect.button'] = '连接 Paymos';
$lang['Paymos.connect.reconnect'] = '重新连接 Paymos';
$lang['Paymos.connect.starting'] = '正在启动连接…';
$lang['Paymos.connect.waiting'] = '正在等待 Paymos 授权。';
$lang['Paymos.connect.open'] = '打开授权页面';
$lang['Paymos.connect.blocked'] = '浏览器拦截了授权标签页。';
$lang['Paymos.connect.code'] = '验证码：';
$lang['Paymos.connect.failed'] = '无法连接 Paymos，请确认已启用 HTTPS 后重试。';
$lang['Paymos.connect.https_required'] = '连接 Paymos 前，Blesta 必须通过 HTTPS 提供服务。';

$lang['Paymos.mode'] = '模式';
$lang['Paymos.mode.sandbox'] = '沙盒（测试）';
$lang['Paymos.mode.live'] = '正式（主网）';
$lang['Paymos.mode_note'] = '先使用沙盒模式进行测试付款，准备好处理真实账单后再切换到正式模式。';

$lang['Paymos.button_text'] = '按钮文字';
$lang['Paymos.button_text_note'] = '显示在 Blesta 账单付款按钮上的文字。';
$lang['Paymos.button_text_default'] = '通过 Paymos 付款';

$lang['Paymos.webhook_url'] = 'Webhook 回调地址';
$lang['Paymos.webhook_url_note'] = '连接 Paymos 时自动为公司 1（/callback/gw/1/paymos/）注册。';

$lang['Paymos.buildprocess.submit'] = '通过 Paymos 付款';
$lang['Paymos.buildprocess.note'] = '您将被跳转至 Paymos 收银台，使用 USDT、USDC、USD1、DAI 或 XAUT 完成付款。';

$lang['Paymos.refunds.title'] = 'Paymos 退款';
$lang['Paymos.refunds.operation'] = '操作编号';
$lang['Paymos.refunds.type'] = '退款类型';
$lang['Paymos.refunds.cash_status'] = '出款状态';
$lang['Paymos.refunds.blesta_record'] = 'Blesta 记录';
$lang['Paymos.refunds.review_reason'] = '审核原因';
$lang['Paymos.refunds.amount'] = '金额';
$lang['Paymos.refunds.button_full'] = '全额退款';
$lang['Paymos.refunds.button_overpayment'] = '退还多付款';
$lang['Paymos.refunds.external_badge'] = '由 Paymos 控制台发起的退款';
$lang['Paymos.refunds.empty'] = '此账单暂无 Paymos 退款记录。';
$lang['Paymos.refunds.reconnect_hint'] = '当前连接未启用退款能力，请在网关设置中点击「重新连接 Paymos」。';
$lang['Paymos.refunds.native_in_progress'] = 'Paymos 退款正在处理中。待 Paymos 确认完成后，Blesta 会自动将交易标记为已退款；可在「Paymos 退款」面板查看进度。';
$lang['Paymos.refunds.native_failed'] = 'Paymos 退款未能完成，请在「Paymos 退款」面板查看详情。';

$lang['Paymos.!error.mode.valid'] = '请选择有效模式：沙盒或正式。';
$lang['Paymos.!error.button_text.length'] = '按钮文字长度不能超过 100 个字符。';
$lang['Paymos.!error.not_connected'] = 'Paymos 尚未连接，请打开网关设置并点击「连接 Paymos」。';
$lang['Paymos.!error.checkout.failed'] = 'Paymos 暂时不可用，请重试或联系客服。';
$lang['Paymos.!error.payment_cannot_be_used'] = '此支付方式不适用于该账单。';
$lang['Paymos.!error.replacement_blocked'] = '此账单需要商家审核后才能继续付款，请联系客服。';
$lang['Paymos.!error.webhook.invalid'] = 'Paymos Webhook 签名验证失败。';
$lang['Paymos.!error.refund.partial_unsupported'] = '不支持部分退款。Paymos 仅支持由服务端计算的全额退款与多付款退款。';
$lang['Paymos.!error.refund.failed'] = '无法向 Paymos 提交退款请求。';
$lang['Paymos.!error.refund.access_denied'] = '管理 Paymos 退款需要管理员权限。';

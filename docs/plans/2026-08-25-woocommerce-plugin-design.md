# Waffo Pancake WooCommerce 插件 — 设计文档

- 日期：2026-08-25
- 状态：草案（含若干待确认事项，见文末）
- 项目：`waffo-pancake-woocommerce`（新建独立仓库）

## 1. 背景与目标

为使用 WooCommerce 的商户提供一个支付网关插件，接入 Waffo Pancake 完成收款。支付模式为托管收银台跳转（下单后跳转到 Waffo 托管的 checkout 页面完成支付，支付后回跳到 WooCommerce），概念上接近 PayPal Standard，但 Waffo 的具体机制有几个关键差异（见第 3 节）。

插件需要同时支持：
- 一次性商品的支付（`onetime-order`）
- 订阅制商品的支付（`subscription-order`），需要商户同时安装官方 **WooCommerce Subscriptions** 插件配合

## 2. 技术路线

插件继承 WooCommerce 标准的 `WC_Payment_Gateway` 基类，这是生态内官方支付插件（PayPal、Stripe 等）的通用做法，可以自动获得：
- 后台「支付方式」设置页框架
- 结账页选项展示
- 与 WooCommerce 订单状态机、退款界面的原生集成点

## 3. 支付流程

### 3.1 关键前提（调研澄清，非常规心智模型）

Waffo Pancake 的托管收银台是**两段式**的，和「一次调用即拿到最终支付页 URL」的模式不同：

1. 商户服务端调用 `create-checkout-session` → 拿到的 `checkoutUrl` 是 **Waffo 自己的收银台页面**，不是 PSP 的支付页
2. 买家在 Waffo 收银台页面填写邮箱/账单信息后，由**收银台前端**调用 `create-order` → 才拿到 PSP（推测为 Stripe，未在文档中明文确认）的真正支付页 URL

此外：
- **没有 `cancelUrl`**：全仓库未发现取消回跳机制，用户中途放弃无法被商户感知
- **回跳 `successUrl` 不带任何 query 参数**（源码确认为纯静态 `<Link>`），无法用于识别订单或验证支付结果
- 因此：**插件绝不能依赖回跳判断支付是否成功**，必须依赖 webhook，并辅以主动查询兜底

### 3.2 一次性支付流程

```
买家在WooCommerce结账页选择"Waffo Pancake"
  → 插件 process_payment() 调用 create-checkout-session
    （商户API Key签名，携带 orderMerchantExternalId = WooCommerce 订单号，用于后续webhook关联）
  → 拿到 checkoutUrl，重定向买家跳转过去
  → 买家在Waffo收银台填写账单信息 → 收银台前端调 create-order → 跳转PSP支付页完成支付
  → 支付完成 → 跳回Waffo收银台 success 页 → 买家点击"Back to Store"
    → 跳回WooCommerce的 successUrl（不带参数，仅展示"订单处理中，请稍候"提示页，不在此处标记订单完成）
  → 【状态确认的唯一权威来源】Waffo webhook 推送 order.completed 到插件注册的端点
    → 验签 → 按 eventId 去重 → 用 orderMerchantExternalId 匹配 WooCommerce 订单 → 更新状态
  → 【兜底】WP-Cron 定时轮询长时间处于中间状态的订单，主动查询订单状态，防 webhook 延迟/丢失
```

### 3.3 订阅流程

与一次性支付类似，改为调用 `subscription-order/create-order`。商户须安装 WooCommerce Subscriptions 插件；插件通过其扩展点，把 Waffo 的订阅事件（`subscription.activated` / `subscription.payment_succeeded` / `subscription.updated` / `subscription.canceling` / `subscription.uncanceled` / `subscription.canceled` / `subscription.past_due`）映射为 WC 订阅对象的状态变更与续费记录。

## 4. 组件结构

```
waffo-pancake-woocommerce/
├── waffo-pancake-woocommerce.php            # 插件入口，注册 hooks、声明 WooCommerce 依赖
├── includes/
│   ├── class-wc-gateway-waffo-pancake.php   # 继承 WC_Payment_Gateway
│   │                                          #   - process_payment(): 创建 checkout session 并重定向
│   │                                          #   - process_refund(): 对接退款（细节待第7节确认后定稿）
│   ├── class-waffo-api-client.php           # REST 调用封装 + RSA-SHA256 请求签名
│   ├── class-waffo-webhook-handler.php      # 注册 REST 端点接收 webhook：验签、去重、状态映射
│   ├── class-waffo-order-reconciler.php     # WP-Cron 兜底：轮询未确认订单的真实状态
│   ├── class-waffo-subscriptions-bridge.php # 检测并对接 WooCommerce Subscriptions（未安装则不启用）
│   └── class-waffo-refund-handler.php       # 退款请求发起与状态跟踪
├── admin/
│   └── 设置页（继承 WC_Payment_Gateway::init_form_fields）
└── docs/
    └── plans/2026-08-25-woocommerce-plugin-design.md（本文档）
```

## 5. 后台设置字段

| 字段 | 说明 |
|---|---|
| Merchant ID | `MER_xxx`，商户在 Waffo Dashboard 生成 API Key 时获得 |
| Environment | `test` / `prod`，对应不同 RSA 密钥对，互相隔离 |
| Private Key (PEM) | 商户从 Waffo Dashboard 生成后粘贴；存于 `wp_options`，避免明文暴露在页面或日志中 |
| Waffo Public Key | 用于 webhook 验签；获取渠道待确认（见第 7 节），当前设计为手动粘贴 |
| Debug Log | 开关，记录 API 请求/响应及 webhook 处理过程，便于排查；不记录私钥/完整签名等敏感值 |

## 6. 订单状态映射

| Waffo 事件 / 状态 | WooCommerce 订单状态 |
|---|---|
| 下单后等待 webhook 确认 | `on-hold`（不用 `pending`，避免被 WooCommerce 默认的未付款订单自动取消定时任务误杀） |
| `order.completed` | `processing`（虚拟商品可再自动流转为 `completed`） |
| `refund.succeeded` | 触发 WooCommerce 部分/全额退款记录 |
| `refund.failed` | 保留原状态，后台展示失败提示 |
| 长时间（阈值可配置，默认 2 小时）未收到 webhook | 触发 Cron 主动查询：查到 `completed` 则补齐状态；查到 session 已过期/订单未创建则转 `failed`，允许买家重新发起支付 |

## 7. 安全设计

- **私钥存储**：RSA 私钥仅保存在 `wp_options`，仅用于 `openssl_sign()` 签名请求，不做任何外发；后续可评估引入应用层加密存储
- **Webhook 验证**：
  - 必须读取 **raw request body**（`php://input`），不能使用 WordPress 已解析的 `$_POST`——因为签名基于原始字节流，反序列化再序列化会导致字段顺序/空格差异使验签失败
  - 校验 `X-Waffo-Signature: t=<timestamp>,v1=<signature>` 中的时间戳新鲜度（防重放）
  - 按 webhook payload 中的 `eventId` 去重（用 transient 或专用小表记录已处理事件，防止 Waffo 端重试导致重复处理）
- **API 请求签名**：`X-Merchant-Id` / `X-Timestamp` / `X-Signature`（RSA-SHA256），PHP 侧用 `openssl_sign()` + `OPENSSL_ALGO_SHA256` 实现，规范参照调研中 Python/Node 签名示例移植
- 所有外呼请求强制 HTTPS

## 8. 错误处理

| 场景 | 处理方式 |
|---|---|
| `create-checkout-session` 调用失败（4xx/5xx） | 向买家展示友好错误提示；记录日志；订单不进入 `on-hold`，允许重新下单 |
| Webhook 验签失败 | 返回 401；记录告警日志（可能是密钥不匹配或恶意请求） |
| Checkout session 过期（用户中途放弃，超过 45 分钟未完成） | 订单保留 `on-hold`；Cron 轮询发现 session 已失效但订单未完成时，允许买家在订单页重新发起支付（生成新 session） |
| 退款请求失败 | 状态回滚为退款前状态，后台展示失败原因 |

## 9. 测试计划

- 使用 test 环境密钥对，完整走通：下单 → 收银台 → 测试卡支付 → webhook 回调 → 订单状态正确流转
- 使用 Waffo 提供的「发送测试 webhook」接口，验证插件端点验签、去重逻辑正确
- 模拟 webhook 不可达，验证 Cron 兜底轮询能补齐订单状态
- 订阅场景：验证各订阅事件正确驱动 WooCommerce Subscriptions 状态机与续费记录
- 边界场景：
  - 零小数货币（JPY/KRW/VND，最小单位为 1 而非 100）金额换算正确性
  - 部分退款金额计算
  - Webhook 重复投递时的幂等性（不会重复处理同一订单）

## 10. 待确认事项

以下信息在调研阶段未能从现有文档/代码中完全确认，设计基于合理推断，**实现前需要与 Waffo 后端团队或业务方核实**：

1. 商户模式下 `create-checkout-session` 与 `issue-session-token` 的完整接口文档（当前文档仓库中只有反向引用，无实体文档）
2. 商户服务端调用 checkout/order 相关接口时，认证方式是否也是 RSA 签名（还是简单 Bearer Key）
3. Webhook 验签所需的 Waffo 平台公钥获取渠道（`.well-known` 端点？需申请下发？test/prod 是否两把不同的公钥？）
4. **退款审核机制**：调研阶段从源码（`refund.ts`、`review-refund-ticket.md`）确认退款为工单制，需 platform admin 手动 approve 才会真正向 PSP 发起退款；但讨论中出现"商户发起退款无需审核"的说法，双方信息存在冲突，**需业务方核实退款的真实审核流程**，这会直接影响插件退款按钮的交互设计与文案
5. Webhook 投递失败后的重试次数/退避策略
6. GraphQL 查询接口是否支持 API Key（RSA 签名）认证，还是仅支持 JWT
7. 是否已有官方 SDK 或其他电商平台（如 Shopify）插件实现可供参考架构对齐

## 11. 参考来源

- `~/Projects/waffo-pancake-api-docs`（主要信息源）
- `~/Projects/waffo-pancake-order-service`（checkout session / 订单 / 退款资源层源码）
- `~/Projects/waffo-pancake-notify-service`（webhook 签名与 payload 构造源码）
- `~/Projects/Waffo-pancake-checkout`（收银台前端，验证回跳行为）

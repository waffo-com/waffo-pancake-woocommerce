# Waffo Pancake for WooCommerce

WooCommerce payment gateway for [Waffo Pancake](https://pancake.waffo.ai), the
Merchant of Record for software and digital products. Buyers are redirected to
the Waffo hosted checkout; order state is driven by signed webhooks, with a
polling fallback.

> 🧪 **Status: beta.** Not yet on WordPress.org — install the zip
> from [GitHub Releases](https://github.com/waffo-com/waffo-pancake-woocommerce/releases).

## Installation

1. Download `waffo-pancake-woocommerce-<version>.zip` from the latest
   [release](https://github.com/waffo-com/waffo-pancake-woocommerce/releases)
   (not the "Source code" archive — it lacks the `vendor/` autoloader).
2. WordPress admin → Plugins → Add New → Upload Plugin → select the zip → Activate.
3. Configure as described below.

## Supported in this version

- ✅ One-time payments for simple products (buyer pays once via Waffo hosted checkout)
- ✅ Both the block-based Checkout (WooCommerce default since 8.3) and the classic shortcode checkout
- ✅ HPOS (High-Performance Order Storage)
- ✅ Refunds initiated from the WooCommerce order screen
- ❌ Subscriptions / recurring billing (planned; subscription webhooks are only logged as order notes)
- ❌ Variable products (one WooCommerce product maps to one Waffo Product ID)

> ⚠️ **Refunds:** clicking Refund in WooCommerce submits a refund request to
> Waffo, which is processed asynchronously. WooCommerce records the refund
> immediately; if Waffo later sends `refund.failed`, the plugin removes that
> refund record and restores the order status (since 0.1.1). Check the final
> refund status in the Waffo Dashboard.

## What it does

- `WC_Payment_Gateway` integration: settings page, checkout option, refunds
- RSA-SHA256 request signing, webhook signature verification, `eventType`+`eventId` dedup
- Webhook → order status mapping with a WP-Cron reconciler as fallback
  (every 15 minutes, looks up unpaid orders by `orderMerchantExternalId`)

## Configuration

WooCommerce → Settings → Payments → Waffo Pancake:

| Setting | Where to find it |
|---------|------------------|
| Environment | Must match the environment your API key was created in. Selects the webhook verification key; API requests always go to `https://api.waffo.ai`. |
| Merchant ID / Private Key | Waffo Dashboard → API & Development. Create the key in Test or Production. |
| Store ID | Waffo Dashboard → store settings (`STO_xxx`). Required for webhook authorization and the reconciliation job. |
| Waffo Tax Category | Tax category sent in `priceSnapshot`; prices are assumed tax-inclusive. |

Then register the webhook in Waffo Dashboard → Settings → Webhooks, channel
`http`, URL:

```
https://your-store.example/wp-json/waffo-pancake/v1/webhook
```

Subscribe at least to `order.completed`, `refund.succeeded` and `refund.failed`.
Each WooCommerce product that uses this gateway needs its Waffo Product ID set
in the product edit screen.

## Requirements

PHP 8.1+, WordPress with WooCommerce. Composer for development.

## Release

`readme.txt` is the WordPress.org listing. To build an installable zip:

```bash
git archive HEAD | tar -x -C build/waffo-pancake-woocommerce
cd build/waffo-pancake-woocommerce && composer install --no-dev --optimize-autoloader
rm -rf tests docs phpunit.xml.dist CONTRIBUTING.md .gitignore
cd .. && zip -r waffo-pancake-woocommerce-<version>.zip waffo-pancake-woocommerce
```

Bump the version in three places: plugin header `Version`, `WAFFO_PANCAKE_WC_VERSION`, and `Stable tag` in `readme.txt`.

## Development

```bash
composer install
vendor/bin/phpunit
```

Design doc and implementation plan: [`docs/plans/`](./docs/plans).

## Related

- [`@waffo/pancake-migrate`](https://github.com/waffo-com/waffo-pancake-migrate) — move your catalog from Stripe / Creem
- [TypeScript SDK](https://github.com/waffo-com/waffo-pancake-sdk-ts) · [Go SDK](https://github.com/waffo-com/waffo-pancake-sdk-go) · [Next.js checkout](https://github.com/waffo-com/waffo-pancake-sdk-nextjs)
- [Developer docs](https://docs.waffo.ai)

## License

GPL-2.0-or-later, as required by WordPress.org. See [LICENSE](./LICENSE).

# Waffo Pancake for WooCommerce

WooCommerce payment gateway for [Waffo Pancake](https://pancake.waffo.ai), the
Merchant of Record for software and digital products. Buyers are redirected to
the Waffo hosted checkout; order state is driven by signed webhooks, with a
polling fallback.

> 🚧 **Status: in development, not yet released on WordPress.org.**
> `main` holds the reviewed scaffold; `feature/real-api-integration` wires the
> gateway to the live Pancake API and is under review.

## What it does

- One-time and subscription products (`onetime-order` / `subscription-order`);
  subscriptions need the official WooCommerce Subscriptions plugin
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
| Store ID | Waffo Dashboard → store settings (`STO_xxx`). Required by the reconciliation job. |
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

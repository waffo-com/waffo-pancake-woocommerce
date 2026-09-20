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
- RSA-SHA256 request signing, webhook signature verification, `eventId` dedup
- Webhook → order status mapping with a WP-Cron reconciler as fallback

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

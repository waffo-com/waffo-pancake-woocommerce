# Contributing

## Layout

One directory per platform. A plugin directory is self-contained: its own
dependency manifest, tests, docs and license. Nothing is shared across
directories except this README and the issue tracker.

```
woocommerce/   PHP 8.1+, WooCommerce payment gateway (WC_Payment_Gateway)
```

## WooCommerce plugin

```bash
cd woocommerce
composer install
vendor/bin/phpunit
```

Design notes and the implementation plan live in `woocommerce/docs/plans/`.
The webhook public keys in `includes/Signing/Waffo_Webhook_Public_Keys.php`
are placeholders until the platform keys are published; signature
verification fails closed until they are filled in.

Branches:

- `main` — reviewed scaffold (signing, money, webhook dedup, status mapping)
- `feature/real-api-integration` — checkout session / refund / webhook
  controller wired to the real Pancake API, under review

## Proposing a new platform

Open an issue first describing the platform's payment-extension model
(redirect vs. embedded, how webhooks reach the store, subscription support).
The WooCommerce design doc is a good template for what to cover.

## Rules

- Never commit merchant keys, `.pem` files or real `MER_` / `STO_` IDs.
  Test fixtures use throwaway keys generated for the test suite only.
- Conventional commit messages (`feat:`, `fix:`, `docs:`, `test:`).
- Every behaviour change comes with a test.

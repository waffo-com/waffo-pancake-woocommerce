# Contributing

## Setup

```bash
composer install
vendor/bin/phpunit
```

Tests are plain PHPUnit; the WordPress / WooCommerce runtime is stubbed in
`tests/bootstrap.php`. Every behaviour change comes with a test.

## Layout

```
waffo-pancake-woocommerce.php   plugin entry, registers the gateway
includes/Gateway/               WC_Payment_Gateway subclass
includes/Signing/               request signer, webhook verifier, platform public keys
includes/Dedup/                 eventId de-duplication (transient-backed)
includes/Money/                 minor-unit conversion
includes/Order/                 webhook event → order status mapping
docs/plans/                     design doc + implementation plans
```

The webhook public keys in `includes/Signing/Waffo_Webhook_Public_Keys.php`
(on `feature/real-api-integration`) are placeholders until the platform keys
are published; verification fails closed until they are filled in.

## Branches

- `main` — reviewed scaffold
- `feature/real-api-integration` — checkout session / refund / webhook
  controller against the real Pancake API, under review

## Rules

- Never commit merchant keys, `.pem` files or real `MER_` / `STO_` IDs.
  `tests/fixtures/*.pem` are throwaway keys generated for the test suite.
- Conventional commit messages (`feat:`, `fix:`, `docs:`, `test:`).
- Say how you verified the change in the PR.

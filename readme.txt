=== Waffo Pancake for WooCommerce ===
Contributors: TODO-wporg-username
Tags: payments, checkout, merchant of record, digital products, payment gateway
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments in WooCommerce through Waffo Pancake, the Merchant of Record for software and digital products.

== Description ==

Waffo Pancake for WooCommerce adds Waffo Pancake as a payment method at checkout. Buyers are redirected to the secure Waffo hosted checkout page to pay. Waffo Pancake acts as the Merchant of Record, handling payment processing and sales tax for software and digital products.

**Features**

* Waffo hosted checkout: card data never touches your store
* Order status is updated from signed Waffo webhooks
* Background reconciliation every 15 minutes recovers orders if a webhook is missed
* Refunds can be requested from the WooCommerce order screen

**Current limitations**

* One-time payments for simple products only. Subscriptions and variable products are not supported yet.
* Each WooCommerce product must be linked to a Waffo product by entering its Waffo Product ID.
* Prices are assumed to include tax (WooCommerce setting "Prices entered with tax" = Yes).

A Waffo Pancake merchant account is required. Sign up at [pancake.waffo.ai](https://pancake.waffo.ai).

== External services ==

This plugin connects to the Waffo Pancake service, operated by Waffo, to create payments, process refunds and confirm order status. It cannot work without this service.

**Waffo Pancake API** (`https://api.waffo.ai`)

* When a buyer places an order with this payment method, the plugin sends the Waffo Product ID, order currency and total amount, tax category, the WooCommerce order number, the buyer's billing email address and the store's order-received URL, to create a checkout session.
* When the store manager issues a refund, the plugin sends the Waffo payment ID, refund amount, currency, refund reason and a refund reference.
* Every 15 minutes, for orders still awaiting payment, the plugin sends the Waffo Store ID and the WooCommerce order number to look up the payment status.
* Every request includes the merchant's Waffo Merchant ID and a signature created with the merchant's private key. The private key itself is never sent.

**Waffo hosted checkout** (`https://checkout.waffo.ai`)

* The buyer is redirected to the Waffo checkout page to enter payment details. Payment details are collected by Waffo, not by this plugin.

**Waffo webhooks**

* Waffo sends payment and refund notifications to the store's REST endpoint `/wp-json/waffo-pancake/v1/webhook`. Each notification is verified with Waffo's public key before it is processed.

Waffo terms and policies:

* Developer Terms: https://waffo.ai/en/developer-terms
* Buyer Terms: https://www.waffo.ai/en/buyer-terms
* Privacy Policy: https://waffo.ai/en/privacy

== Installation ==

1. Install and activate WooCommerce.
2. Install this plugin from Plugins → Add New, or upload the plugin zip, then activate it.
3. Go to WooCommerce → Settings → Payments → Waffo Pancake and fill in:
   * Environment: Test or Production. It must match the environment your API key was created in.
   * Merchant ID and Private Key: from Waffo Dashboard → API & Development.
   * Store ID (STO_xxx): from your Waffo store settings.
4. In Waffo Dashboard → Settings → Webhooks, add an `http` webhook pointing to `https://your-store.example/wp-json/waffo-pancake/v1/webhook` and subscribe to at least `order.completed`, `refund.succeeded` and `refund.failed`.
5. Edit each product you sell through Waffo and enter its Waffo Product ID (PROD_xxx) in the General tab.

== Frequently Asked Questions ==

= Do I need a Waffo account? =

Yes. You need a Waffo Pancake merchant account and products created in the Waffo Dashboard.

= Are subscriptions supported? =

Not yet. This version supports one-time payments for simple products. Subscription support is planned.

= How do refunds work? =

When you click Refund on an order, the plugin submits a refund request to Waffo and WooCommerce records the refund right away. Waffo processes the request asynchronously. If Waffo reports the refund as failed, the plugin removes that refund record from the order, restores the order status and adds an order note. Always check the Waffo Dashboard for the final refund result.

= The buyer paid but the order is still On hold. What should I check? =

Make sure the webhook is registered in the Waffo Dashboard with the correct URL and that the Environment setting matches your API key. The background reconciliation job also checks unpaid orders every 15 minutes, which requires the Store ID to be set. Enable Debug Log in the plugin settings and look at WooCommerce → Status → Logs (source `waffo_pancake`).

== Changelog ==

= 0.1.1 =
* Refunds that Waffo reports as failed are now removed from the WooCommerce order automatically, and the order status is restored.
* Security hardening: escaped error messages and an extra nonce check when saving the Waffo Product ID.
* Added plugin headers and readme for WordPress.org.

= 0.1.0 =
* First beta release: one-time payments through Waffo hosted checkout, signed webhooks, background reconciliation and refund requests.

== Upgrade Notice ==

= 0.1.1 =
Failed refunds are now rolled back in WooCommerce automatically.

# Shopify Payment Fix Plugin

Registers a plentymarkets event procedure that inspects Shopify orders with split payments and adds a dedicated PayPal payment when the combination "Shopify Payments + PayPal" could not be imported correctly.

## Features

- Registers an order event procedure (`Add Shopify PayPal payment`).
- Fetches Shopify order details via GraphQL by using the plentymarkets external order ID.
- Detects split payments that include PayPal and creates a PayPal payment in plentymarkets.
- Avoids duplicate payments by checking existing PayPal transactions on the order.

## Configuration

Configure the plugin in the plenty back end under *Plugins » Plugin overview* after installing it.

| Key | Description |
| --- | --- |
| `global.shopName` | Shopify shop subdomain (without `.myshopify.com`). |
| `global.apiVersion` | Shopify Admin GraphQL API version, e.g. `2025-01`. |
| `global.accessToken` | Admin API access token for the Shopify custom/private app. |
| `global.paypalMopId` | plentymarkets method of payment ID that represents PayPal. |
| `global.enableDebugLog` | Optional flag to log additional info level messages. |

Values are stored via `config.json`; see `docs/plugin-configuration-howto.md`.

## Event Procedure Usage

1. Install and deploy the plugin.
2. Navigate to *Setup » Orders » Events* and create a procedure for the relevant order event.
3. Choose the action **Add Shopify PayPal payment** (category: the plugin name) and save the procedure.
4. Ensure the procedure runs after the order is imported via Shopify.

Event procedure basics are documented in `docs/event-procedures.md`.

## Manual Test Flow

1. Import or create a Shopify order whose external order ID corresponds to a real Shopify order with payment gateways `["shopify_payments", "paypal"]`.
2. Trigger the configured event procedure (e.g. manually execute it or re-trigger the import event).
3. Check Data log for entries with identifier `PaymentSyncService_ensurePaypalPayment` if debug logging is enabled.
4. Verify that the plentymarkets order now contains a PayPal payment tied to the configured method-of-payment ID and that the payment amount matches the PayPal portion in Shopify.
5. Re-run the procedure to confirm that no duplicate payment is created.

## Notes

- The Shopify API call is executed with cURL and expects the Admin API access token to be valid for GraphQL.
- The GraphQL query fetches up to 50 transactions. Adjust if orders routinely contain a higher number of transactions.
- Payment properties include the Shopify transaction ID (type 1), a booking text (type 22), and a custom origin marker (type 23) for traceability.
- Error handling writes to the Data log; add translations if you extend logging with new message codes.


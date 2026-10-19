# LoyaltyEngage LoyaltyShop for Magento 2

A Magento 2 module that allows customers to add products to their cart based on loyalty points.

## Features

### Cart Safety and Recovery

Minimum spend is checked using the quote's store configuration after totals collection and before order submission, including quantity-only reductions. Ineligible rewards are removed and their removal events persisted once. If submission removes rewards, customers must review their cart before proceeding.

Physical reservation recovery retires an applied journal only when an exact completed removal cycle is proven. It examines at most 1,001 recent removal rows (up to 1,000 provable cycles); older or ambiguous history remains protected. It never blindly repeats an uncertain purchase. Recovery processes at most 25 records per run with a 45-second budget checked between records; an in-flight API request can exceed that budget. Supporting indexes belong only to loyaltyshop_mutation, not Magento sales tables.

Shipping rate collection is forced only when loyalty eligibility changes; Magento's own invalidation for address/cart changes remains intact.

### General Features

- Add products to cart based on loyalty points
- Automatic cart expiry for loyalty-based items
- Order processing for loyalty-based purchases
- Admin configuration for loyalty program settings
- Integration with Magento's customer and cart systems

## Requirements

- PHP 8.1, 8.2, 8.3 or 8.4, matching the PHP version supported by your Magento release
- Magento 2.4.x (the local verification environment uses Magento 2.4.8 / PHP 8.3)
- Composer

## Installation

### Via Composer (Recommended)

1. Add the repository to your Magento 2 project's `composer.json`:

```bash
composer require loyaltyengage/loyaltyshop
```

2. Enable the module:

```bash
bin/magento module:enable LoyaltyEngage_LoyaltyShop
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Cron and Consumer Ownership

The module does not write to `app/etc/env.php` or change `cron_consumers_runner`. Manage these settings through your deployment. Existing runner settings are not restored or modified on upgrade, including settings written by older module versions.

New events use `loyaltyshop_outbox`. Ensure your platform runs Magento's `default` cron group: `loyaltyshop_deliver_events` runs every minute, `loyaltyshop_recover_mutations` every five minutes and `loyalty_cart_expiry` every fifteen minutes. Running AMQP consumers alone does not deliver the outbox. Inspect `cron_schedule` for these jobs and use `bin/magento loyalty:events` to inspect delivery state.

The six consumers in `etc/queue_consumer.xml` remain for draining legacy messages. They explicitly use the `db` connection, independently of your platform's AMQP workers. If you have a legacy backlog, run the needed consumers using your existing worker supervisor, for example:

```bash
bin/magento queue:consumers:list
bin/magento queue:consumers:start loyaltyshop_purchase_event_consumer --max-messages=100
```

The other declared names are `loyaltyshop_return_event_consumer`, `loyaltyshop_free_product_purchase_event_consumer`, `loyaltyshop_free_product_remove_event_consumer`, `loyaltyshop_review_event_consumer` and `loyaltyshop_redeem_discount_event_consumer`. These transfer legacy messages to the outbox, which still requires the default cron group.

If your deployment intentionally uses Magento's cron consumer runner instead of supervised workers, maintain its `cron_consumers_runner` configuration in your deployment template. Preserve platform-wide choices for `cron_run`, `max_messages` and the consumer allowlist. No runner setting is required specifically for new LoyaltyShop outbox events. Review any old auto-added entries with your operations team; this upgrade cannot infer which entries your deployment owns.

### Manual Installation

1. Create a directory structure in your Magento installation: `app/code/LoyaltyEngage/LoyaltyShop`
2. Download the module and extract its contents to the directory you created
3. Enable the module:

```bash
bin/magento module:enable LoyaltyEngage_LoyaltyShop
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

## Configuration

1. Log in to your Magento Admin Panel
2. Navigate to **Stores > Configuration > LoyaltyEngage > LoyaltyShop**
3. Configure the following settings:
   - API Connection Settings
   - Cart Expiry Options
   - Order Processing Settings

## Usage

### Purchase and Return Tax Basis

Under **Stores > Configuration > Loyalty Engage > Loyalty > Loyalty Export Settings**, set **Purchase and Return Price Tax Basis** to **Excluding Tax** (default) or **Including Tax**. It is configurable per website/store view, using the order's store for both events. The default preserves existing export behavior.

This changes the product unit price in newly generated Purchase and Return payloads, in the order currency. Inclusive mode reads the stored `price_incl_tax`, preserving the historical tax treatment without querying current catalog prices or tax rules. For creditmemos missing that value, the original order item's inclusive unit price is used. If neither historical value exists, the existing exclusive price is retained rather than inventing tax or exporting zero. Zero prices remain zero. Quantities, coupon/discount handling, shipping, adjustments and tier-price exclusions are unchanged.

Already queued or delivered events are not recalculated. The setting is read when an event is created, not snapshotted at purchase time: changing it can make a later Return use a different basis from its older Purchase. Coordinate a cutover and historical returns with Loyalty Engage, rather than switching during an active refund cycle. This setting selects a tax basis; it does not change the payload into the net refunded total after coupons or adjustments.

### Exclude Quantity-Tier Discounts from Purchase Events

Go to **Stores > Configuration > Loyalty Engage > Loyalty > Loyalty Export Settings > Exclude Applied Tier Prices from Purchase Export**. The setting defaults to **No** and can be overridden per website or store view. Enable Purchase Export to display it.

When enabled, newly placed Magento-quote orders record whether a quantity tier above one actually lowered each order line's price. Only those lines are excluded from Purchase events. Products merely having tier prices, quantities below the threshold, quantity-one group prices, and a lower/equal special or catalog-rule price are not automatically excluded. Native configurable selection pricing and fixed/dynamic bundle pricing are supported. Explicit custom quote prices are not classified as native tier discounts.

Cart price rules, coupon codes and order-item `discount_amount` do not trigger this exclusion. A coupon-only discounted line stays eligible; a line with both an applied quantity tier and a coupon is excluded because of the tier, not the coupon.

For example, with regular price 15.97, tier 10 at 13.29 and tier 50 at 12.59: 9 units remain eligible, while 10 or 50 units are excluded if their tier determines the price. An active special price of 11.56 keeps the line eligible because it beats both tiers. All units on a tier-priced line are excluded, not only the units above the threshold.

The decision is stored in existing order-item `product_options`, without schema changes to sales tables or product lookups during Purchase/Return export. Returns use that same snapshot even after settings, quantities or catalog prices change. Mixed orders still export their eligible lines; an entirely excluded order produces no empty Purchase event. Physical-redemption completion and coupon-redemption events are unchanged.

This is an **earning/export filter**, not a checkout discount-stacking restriction: it does not block coupons or spending coins. Existing orders and already queued events are not retroactively changed. Direct imports that bypass Magento quote-to-order conversion have no snapshot and keep the existing export behavior. Test third-party price overrides separately; if pricing inspection fails, checkout continues, a warning is logged, and no exclusion is guessed. The setting-off path performs no additional pricing calculations.

### Frontend

The module adds functionality to the customer's shopping cart, allowing them to:
- Add loyalty-based products to their cart
- View loyalty points balance
- Process orders using loyalty points

### API Endpoints

The REST endpoints require the store's configured Basic Auth credentials. Use the appropriate `/rest/<store-code>/` prefix:

- `POST /V1/loyalty/shop/:customer_id/cart/add`
- `POST /V1/loyalty/shop/:customer_id/cart/add-multiple`
- `POST /V1/loyalty/shop/:customerId/cart/remove`
- `DELETE /V1/loyalty/shop/:customerId/cart`
- `POST /V1/loyalty/discount/:customerId/claim-after-cart`
- `POST /V1/loyalty/customer/update`

Storefront scripts use session-authenticated POST routes `/loyalty/cart/add` and `/loyalty/discount/claim`. The `/loyaltyshop/` aliases remain supported. Prefer a valid Magento `form_key`; same-origin Magento AJAX requests remain compatible.

## Cron Jobs

The module includes the following cron jobs:

- `loyalty_cart_expiry`: Removes expired loyalty items per store every 15 minutes.
- `loyaltyshop_deliver_events`: Delivers the durable event outbox every minute.
- `loyaltyshop_recover_mutations`: Retries local application of confirmed remote reservations every 5 minutes, without buying again.

Magento's normal cron must run. Inspect failures with `bin/magento loyalty:events`.
For upgrade steps, recovery commands, compatibility changes and test instructions, see [Production Readiness](PRODUCTION_READINESS.md).

## Support

For support, please contact:
- Email: support@loyaltyengage.com
- Website: https://loyaltyengage.com

## License

This module is licensed under the MIT License - see the LICENSE file for details.

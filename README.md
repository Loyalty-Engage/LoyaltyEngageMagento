# LoyaltyEngage LoyaltyShop for Magento 2

A Magento 2 module that allows customers to add products to their cart based on loyalty points.

## Features

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

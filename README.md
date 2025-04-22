# LoyaltyEngage LoyaltyShop for Magento 2

A Magento 2 module that allows customers to add products to their cart based on loyalty points.

## Features

- Add products to cart based on loyalty points
- Automatic cart expiry for loyalty-based items
- Order processing for loyalty-based purchases
- Admin configuration for loyalty program settings
- Integration with Magento's customer and cart systems

## Requirements

- PHP 7.4 or higher
- Magento 2.4.x or higher
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

The module provides the following API endpoints:

- `POST /V1/loyaltyengage/cart`: Add loyalty-based products to cart
- `DELETE /V1/loyaltyengage/cart/items/{itemId}`: Remove a specific loyalty item from cart
- `DELETE /V1/loyaltyengage/cart/items`: Remove all loyalty items from cart

## Cron Jobs

The module includes the following cron jobs:

- `loyaltyengage_cart_expiry`: Removes expired loyalty items from carts
- `loyaltyengage_order_place`: Processes loyalty-based orders

## Support

For support, please contact:
- Email: support@loyaltyengage.com
- Website: https://loyaltyengage.com

## License

This module is licensed under the MIT License - see the LICENSE file for details.

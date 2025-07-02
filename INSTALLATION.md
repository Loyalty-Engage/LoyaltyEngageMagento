# LoyaltyEngage LoyaltyShop Installation Guide

This document provides detailed instructions for installing the LoyaltyEngage LoyaltyShop module for Magento 2.

## Prerequisites

Before installing the module, ensure your system meets the following requirements:

- Magento 2.4.x or higher
- PHP 7.4 or higher
- Composer 2.x
- MySQL 5.7 or higher
- Sufficient permissions to install Magento 2 modules

## Installation Methods

### Method 1: Install via Composer (Recommended)

This is the recommended installation method as it allows for easy updates and dependency management.

1. Open a terminal and navigate to your Magento 2 root directory.

2. Add the module to your Composer requirements:

```bash
composer require loyaltyengage/loyaltyshop
```

3. Enable the module:

```bash
bin/magento module:enable LoyaltyEngage_LoyaltyShop
```

4. Run Magento upgrade scripts:

```bash
bin/magento setup:upgrade
```

5. Compile Dependency Injection:

```bash
bin/magento setup:di:compile
```

6. Deploy static content:

```bash
bin/magento setup:static-content:deploy -f
```

7. Clear the cache:

```bash
bin/magento cache:flush
```

### Method 2: Install from GitHub

1. Create the module directory structure in your Magento installation:

```bash
mkdir -p app/code/LoyaltyEngage/LoyaltyShop
```

2. Clone the repository:

```bash
git clone https://github.com/loyaltyengage/loyaltyshop.git app/code/LoyaltyEngage/LoyaltyShop
```

3. Enable the module:

```bash
bin/magento module:enable LoyaltyEngage_LoyaltyShop
```

4. Run Magento upgrade scripts:

```bash
bin/magento setup:upgrade
```

5. Compile Dependency Injection:

```bash
bin/magento setup:di:compile
```

6. Deploy static content:

```bash
bin/magento setup:static-content:deploy -f
```

7. Clear the cache:

```bash
bin/magento cache:flush
```

## Verification

To verify that the module has been installed correctly:

1. Log in to your Magento Admin Panel.

2. Navigate to **Stores > Configuration > LoyaltyEngage > LoyaltyShop**.

3. If you can see the configuration page, the module has been installed successfully.

4. You can also verify the module status using the command line:

```bash
bin/magento module:status LoyaltyEngage_LoyaltyShop
```

## Troubleshooting

If you encounter any issues during installation:

1. Check the Magento logs in `var/log/`.

2. Ensure all Magento permissions are set correctly:

```bash
find var generated vendor pub/static pub/media app/etc -type f -exec chmod u+w {} \;
find var generated vendor pub/static pub/media app/etc -type d -exec chmod u+w {} \;
```

3. If you're using Redis for cache, you might need to flush the Redis cache:

```bash
redis-cli flushall
```

4. For further assistance, contact support at support@loyaltyengage.com.

## Updating the Module

### Via Composer

```bash
composer update loyaltyengage/loyaltyshop
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Via GitHub

```bash
cd app/code/LoyaltyEngage/LoyaltyShop
git pull
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush

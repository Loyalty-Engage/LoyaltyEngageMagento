# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.4.4] - 2026-04-13

### Fixed
- **Cart price rule coupon type stays Specific**: Removed logic that incorrectly upgraded existing rules from `COUPON_TYPE_SPECIFIC` to `COUPON_TYPE_AUTO` when a second discount code was created for the same rule
- **Coupon code field stays empty on rule**: All discount codes are now created exclusively in the "Manage Coupon Codes" section (`is_primary = false`), keeping the rule's "Coupon Code" field empty
- **New rules use auto generation**: New cart price rules now set `use_auto_generation = 1` so multiple coupon codes can be added without manually enabling the checkbox in admin

### Changed
- **Cart price rule settings**: New rules are now created with `uses_per_customer = 0` (unlimited) and `stop_rules_processing = 1` (Discard subsequent rules = Yes)

## [2.4.3] - 2026-04-01

### Fixed
- **Purchase/Return payload: use `identifier` instead of `email`**: LoyaltyEngage API requires `identifier` field (not `email`) for contact identification in Purchase and Return events
- **Purchase/Return payload: price as numeric string**: Price is now formatted as a numeric string (e.g. `"45.00"`) using `number_format()` instead of a PHP float, matching the API's `numeric string` type requirement
- **ReviewConsumer: payload wrapped in array**: Review event payload is now sent as an array of events (`[{...}]`) matching the API's expected format

## [2.4.2] - 2026-04-01

### Fixed
- **Queue consumers not running automatically**: `SimpleConsumerStarter` cron now processes purchase, return, and review consumers based on their respective export settings in admin
  - `loyaltyshop_purchase_event_consumer` → runs only when Purchase Export is enabled
  - `loyaltyshop_return_event_consumer` → runs only when Return Export is enabled
  - `loyaltyshop_review_event_consumer` → runs only when Review Export is enabled
  - Free product consumers always run (core functionality)
- **ConfigureCronConsumers setup patch**: All 5 consumers are now registered in `env.php` for installations using Magento's native `cron_consumers_runner`

## [2.4.1] - 2026-03-27

### Changed
- Remove debug/internal markdown documentation files (ARCHITECTURE, INSTALLATION, QUEUE_SETUP, etc.)
- Remove unused frontend layout files (catalog_category_view, catalog_product_view, checkout_cart_index, checkout_cart_item_renderers)
- Remove unused Luma cart template (`view/frontend/templates/cart/item/default.phtml`)
- Remove `Cron/ConsumerStarter.php` (superseded by `SimpleConsumerStarter`)
- Remove verbose debug logging from all Observers, Plugins, and Crons

### Fixed
- GDPR: mask customer emails in all log statements
- Replace `ObjectManager` usage in `ReviewObserver` with proper dependency injection
- Remove unused `LoggerInterface` dependencies from `CartUpdateObserver`, `CartUpdatePlugin`, `QuoteItemQtyValidatorPlugin`
- Fix typo: `XML_PATH_LOGGEER` → `XML_PATH_LOGGER` in `LoyaltyengageCart`
- Fix typo: `getTenantId()` → `getTenantID()` in `LoyaltyengageCart`
- Fix crontab.xml comment: 'every hour' → 'daily at midnight'

### Added
- `declare(strict_types=1)` to all PHP files missing it
- `debug_logging` and `queue_processing_frequency` defaults to `config.xml`
- Set `logger_enable` default to `0` (off) in `config.xml`

## [2.4.0] - 2026-03-25

### Security
- **API Token Encryption**: Tenant ID and Bearer Token are now stored encrypted in the database using Magento's `Encrypted` backend model
- **Safe Unserialize**: Replaced all `unserialize()` calls with a safe implementation using `['allowed_classes' => false]` to prevent PHP object injection attacks (8 files updated)
- **Shell Command Safety**: Replaced `shell_exec()` with Magento's `ShellInterface` for secure command execution in cron jobs
- **REST API Authentication**: Added `AuthenticationPlugin` to validate Basic Auth credentials for incoming REST API calls from LoyaltyEngage backend
- **Removed Debug Logging**: Replaced all `error_log()` calls with Magento's proper logger to prevent sensitive data exposure

### Added
- **WebApi Authentication Plugin**: New `Plugin/WebApi/AuthenticationPlugin.php` that validates Basic Auth credentials for `/V1/loyalty/` endpoints
- **Encrypted Config Support**: `Helper/Data.php` and `Model/LoyaltyengageCart.php` now properly decrypt encrypted configuration values

### Fixed
- **Credential Decryption**: Fixed issue where encrypted credentials were not being decrypted when making API calls to LoyaltyEngage, causing 401 NO_CREDENTIALS errors

### Changed
- **webapi.xml**: Removed unused anonymous REST API endpoints, keeping only the customer update endpoint which is protected by Basic Auth
- **Logging Improvements**: All shipping plugins now use Magento's logger instead of `error_log()`

### Files Modified
- `etc/adminhtml/system.xml` - Added encryption backend for tenant_id and bearer_token
- `etc/di.xml` - Registered WebApi AuthenticationPlugin
- `etc/webapi.xml` - Secured REST API endpoints
- `Helper/Data.php` - Added EncryptorInterface for credential decryption
- `Model/LoyaltyengageCart.php` - Added EncryptorInterface for credential decryption
- `Cron/ConsumerStarter.php` - Replaced shell_exec with ShellInterface
- `Cron/CartExpiry.php` - Safe unserialize implementation
- `Observer/CartProductAddObserver.php` - Safe unserialize implementation
- `Observer/CartUpdateObserver.php` - Safe unserialize implementation
- `Observer/CartPageViewObserver.php` - Safe unserialize implementation
- `Plugin/CheckoutCartItemRendererPlugin.php` - Safe unserialize implementation
- `Plugin/CartUpdatePlugin.php` - Safe unserialize implementation
- `Plugin/QuoteItemQtyValidatorPlugin.php` - Safe unserialize implementation
- `ViewModel/CartItemHelper.php` - Safe unserialize implementation
- `Plugin/ShippingMethodPlugin.php` - Replaced error_log with logger
- `Plugin/Shipping/FlatratePlugin.php` - Replaced error_log with logger
- `Plugin/Shipping/TableratePlugin.php` - Replaced error_log with logger
- `Plugin/Quote/AddressPlugin.php` - Replaced error_log with logger
- `Setup/Patch/Data/CreateLoyaltyFreeShippingRule.php` - Replaced error_log with logger

## [2.3.0] - 2026-03-20

### Added
- Minimum order value feature for loyalty products
- Configurable error messages with styling options

## [2.2.0] - 2026-02-15

### Added
- Discount code purchase functionality
- Cart rule reuse optimization

## [2.1.0] - 2026-01-27

### Added
- **Automatic Queue Consumer Configuration**: New setup patch that automatically configures `cron_consumers_runner` in `env.php` when the module is installed
- **Queue Setup Documentation**: Added `QUEUE_SETUP.md` with detailed instructions for queue processing setup and troubleshooting
- **Hyvä Compatibility**: Module is now fully compatible with Hyvä themes (backend-only, no frontend templates)

### Fixed
- **Queue Processing Bug**: Fixed `SimpleConsumerStarter` cron job to process BOTH queue consumers:
  - `loyaltyshop_free_product_purchase_event_consumer` (was working)
  - `loyaltyshop_free_product_remove_event_consumer` (was missing - now fixed)
- **Cart Remove Events**: Cart remove events are now properly sent to LoyaltyEngage API

### Changed
- **Improved Logging**: Reduced excessive logging and added email masking for privacy
  - Customer emails are now masked in logs (e.g., `j***@e***.com`)
  - Debug-level logging only when debug mode is enabled
  - Removed redundant log entries

### Security
- **Email Privacy**: Customer email addresses are now masked in all log files

## [2.0.0] - 2026-01-06

### Added
- Customer loyalty tier attributes
- Catalog and Sales rule conditions for loyalty tiers
- Free shipping for loyalty tiers
- REST API for customer loyalty management
- Review export to LoyaltyEngage
- Queue-based event processing

## [1.0.0] - 2025-12-01

### Added
- Initial release
- Add products to cart via LoyaltyEngage API
- Free product purchase tracking
- Basic loyalty integration

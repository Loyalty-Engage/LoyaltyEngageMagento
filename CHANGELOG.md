# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

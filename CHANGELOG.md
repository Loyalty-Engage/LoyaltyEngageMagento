# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.5.2] - 2026-08-14

### Fixed
- **Store-aware order and return queue publishing**: Purchase, return, free product purchase and voucher redeem exports geven nu de `store_id` door in de queue payload, zodat store-scoped exportinstellingen en credentials van de juiste store view worden gebruikt.
- **Store-aware queue consumers and API authentication**: Queue consumers en `ApiClient` lezen nu Loyalty Engage configuratie expliciet in de context van de juiste store, waardoor events niet meer ten onrechte worden overgeslagen of met verkeerde credentials/API-config worden verstuurd in multistore omgevingen.

## [2.5.1] - 2026-08-13

### Added
- **Store-scoped loyalty customer fields**: Loyalty metadata zoals punten, coins en aanvullende klantvelden kunnen nu per store view worden beheerd en via de API op store-niveau worden bijgewerkt in plaats van globaal.
- **Configureerbare Loyalty account-tab**: Een aparte account-tab voor loyalty gegevens toegevoegd, inclusief instelbare titel, configureerbare sorteerpositie en per veld aan/uit-opties met aanpasbare labels vanuit de moduleconfiguratie.
- **Frontend en PageBuilder beschikbaarheid voor loyalty velden**: Loyalty meta fields kunnen nu gecontroleerd beschikbaar worden gemaakt voor zowel storefront rendering als PageBuilder content, zodat merchants dit per veld kunnen inschakelen.

### Changed
- **Accountpagina layout afgestemd op standaard Magento/Hyva accountnavigatie**: De loyalty pagina rendert nu in dezelfde hoofdcontentstructuur als andere accountpagina's, met de navigatie aan de zijkant en consistente spacing/styling.
- **Hyva/Luma compatibiliteit verbeterd**: Frontend rendering is aangescherpt zodat dezelfde loyalty velden en accountweergave in beide omgevingen ondersteund blijven.

### Fixed
- **Remove-from-cart synchronisatie**: Het remove-from-cart event voor loyalty producten is verder rechtgetrokken zodat verwijderingen vanuit de cart correct richting LoyaltyEngage worden doorgezet.
- **Loyalty tab content rendering**: De loyalty account-tab toont nu daadwerkelijk de geconfigureerde meta fields in plaats van een lege pagina of afwijkende layout.

## [2.5.0] - 2026-05-27

### Added
- **Configureerbaar "loyalty product verwijderd" bericht**: Nieuw admin veld `loyalty/general/loyalty_product_removed_message` waarmee de tekst die getoond wordt bij automatisch verwijderen van loyalty producten (wanneer de cart waarde onder het minimum zakt) volledig configureerbaar is via de backend. Ondersteunt `{{minimum}}` en `{{current}}` als placeholders voor de minimale en huidige cart waarde.
  - Nieuw veld "Loyalty Product Removed Message" (textarea) toegevoegd aan *Loyalty Cart Configuration* in `etc/adminhtml/system.xml`.
  - Standaard waarde ingesteld in `etc/config.xml`.
  - Nieuwe methoden `getLoyaltyProductRemovedMessage()` en `getFormattedLoyaltyProductRemovedMessage()` toegevoegd aan `Helper/Data.php`.
  - `Observer/CartItemRemoveObserver.php` gebruikt nu de configureerbare tekst i.p.v. een hardcoded melding.

## [2.4.9] - 2026-05-18

### Removed
- **`Cron/SimpleConsumerStarter.php` verwijderd**: De `loyaltyshop_simple_consumer_starter` cron job veroorzaakte forever-running PHP processen en was redundant. Magento's eigen `consumers_runner` cron (geconfigureerd via `Setup/Patch/Data/ConfigureCronConsumers.php`) beheert de queue consumers automatisch.
- **`loyaltyshop_simple_consumer_starter` cron job verwijderd** uit `etc/crontab.xml`.
- **`Model/Config/Source/QueueFrequency.php` verwijderd**: Source model voor de verwijderde "Queue Processing Frequency" admin instelling.
- **`queue_processing_frequency` admin instelling verwijderd** uit `etc/adminhtml/system.xml` en `etc/config.xml`.
- **`consumers_runner` sectie verwijderd** uit `etc/config.xml`: Deze configuratie hoort thuis in `app/etc/env.php` (beheerd door `ConfigureCronConsumers`), niet in `config.xml`.

## [2.4.8] - 2026-05-14

### Fixed
- **Purchase en Return consumers ontbraken in SimpleConsumerStarter**: De `loyaltyshop_purchase_event_consumer` en `loyaltyshop_return_event_consumer` stonden niet in de `CONSUMERS` array van `SimpleConsumerStarter`, waardoor purchase en return events nooit verwerkt werden via de cron. Beide consumers en hun queue-namen zijn nu toegevoegd.
- **Handler ontbrak in queue_consumer.xml**: De `loyaltyshop_purchase_event_consumer` en `loyaltyshop_return_event_consumer` hadden geen `handler` attribuut in `queue_consumer.xml`, waardoor Magento niet wist welke klasse de berichten moest verwerken.
- **SimpleConsumerStarter herschreven**: De cron verwerkt nu berichten **direct** via de consumer handler (i.p.v. republishen). Leest pending berichten (status 2=new, 5=retry) uit de DB queue, roept de handler aan en zet de status op 4 (complete) of 6 (error). Dit elimineert de afhankelijkheid van een langlopend `queue:consumers:start` process.
- **"Area code is not set" fout opgelost**: `AppState` toegevoegd aan `SimpleConsumerStarter` om de Magento area code in te stellen voor queue verwerking.
- **Double-encoded JSON body**: Berichten in de DB queue zijn dubbel-encoded. De `SimpleConsumerStarter` decodeert nu correct voordat het bericht aan de consumer handler wordt doorgegeven.
- **PurchaseConsumer en ReturnConsumer stuurden verkeerd payload formaat**: De `/api/v1/events` endpoint van LoyaltyEngage verwacht een **array van events** (`[{...}]`). Beide consumers stuurden een enkel object (`{...}`), waardoor LoyaltyEngage het event wel accepteerde (HTTP 200) maar niet verwerkte (`acceptedEventCount: 0`). Fix: `$this->apiClient->post($endpoint, [$payload])` i.p.v. `$this->apiClient->post($endpoint, $payload)`. API response wordt nu ook gelogd.

## [2.4.7] - 2026-05-14

### Added
- **Max loyalty products in cart (configurable)**: New admin setting `loyalty/general/max_loyalty_products` allows merchants to configure the maximum number of different loyalty products a customer can add to the cart. Set to `0` for unlimited (default). The check is enforced in `LoyaltyCart::addProduct()` before the API call is made.
- **Configurable order status for purchase sync**: New admin setting `loyalty/export/purchase_order_status` allows merchants to configure which order status triggers the purchase sync to LoyaltyEngage. Default is `complete`. Previously this was hardcoded in `PurchaseObserver`.
- **Voucher redeem via LoyaltyEngage API**: When a customer applies a coupon code at checkout, the voucher is now also marked as redeemed in LoyaltyEngage via `PUT /api/v1/discount/:discountCode/redeem`. This is handled in both `CouponPostPlugin` (frontend cart) and `CouponManagementPlugin` (REST API/headless). New method `redeemDiscount()` added to `LoyaltyengageCart`.

### Changed
- **`PurchaseObserver`**: Replaced hardcoded `'complete'` status check with `$this->helper->getPurchaseOrderStatus()` to use the configurable admin setting.
- **`CouponPostPlugin`**: Now calls `redeemDiscount()` after `claimDiscount()` when a coupon is applied.
- **`CouponManagementPlugin`**: Now calls `redeemDiscount()` after `claimDiscount()` when a coupon is applied via API.

## [2.4.6] - 2026-05-08

### Added
- **RemoveFromCart event**: New `CartItemRemoveObserver` listens on `sales_quote_remove_item`. When a loyalty product is removed from the Magento cart, a remove event is immediately sent to the LoyaltyEngage API (`/cart/remove`).

### Changed
- **Minimum order value threshold includes tax**: `LoyaltyCart::getCartSubtotalExcludingLoyaltyProducts()` now uses `getRowTotalInclTax()` instead of `getRowTotal()`, so the minimum order value threshold is calculated including VAT/tax.
- **Loyalty product detection in subtotal**: Replaced manual loyalty product detection with the shared `isLoyaltyProduct()` helper method for consistency.

### Fixed
- **Auto-remove loyalty products when cart drops below minimum**: When a regular product is removed and the cart subtotal (incl. tax, excl. loyalty products) drops below the configured minimum order value, all loyalty products are automatically removed from the cart. The LoyaltyEngage API is notified for each removed loyalty product, and a warning message is shown to the customer.

## [2.4.5] - 2026-04-24

### Added
- Implemented new API interface file where missing and mapped existing integrations accordingly.
- Introduced centralized `ApiClient` service to handle all external API (cURL) requests.

### Changed
- Refactored all direct cURL calls across multiple files to use the centralized `ApiClient`.
- Replaced hardcoded URLs with configurable values for better environment management.
- Improved API response handling with standardized status checks across modules.
- Updated API access from anonymous to authenticated by modifying `webapi.xml`.

### Fixed
- Resolved potential security risks related to use of `unserialize()` by reviewing and securing usage.
- Replaced `shell_exec()` usage in cron/consumer scripts with Magento-native alternatives where available.
- Addressed PII exposure by replacing email usage with IDs or hashed values where applicable.

### Removed
- Eliminated duplicated code by introducing reusable utility functions and shared logic.
- Removed redundant and scattered cURL implementations.

### Security
- Strengthened API authentication and authorization mechanisms.
- Mitigated risks of PHP object injection.
- Reduced exposure of sensitive user data (PII).

### Notes
- High-risk change: cURL refactor may impact existing API flows.
- Extensive testing recommended for all API scenarios.


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

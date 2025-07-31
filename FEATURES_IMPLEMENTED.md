# LoyaltyEngage Plugin - New Features Implementation

This document outlines the three new features that have been added to the LoyaltyEngage plugin.

## 1. Review Collection and Export

### Overview
Automatically collects customer reviews and pushes them to the LoyaltyEngage API when reviews are approved.

### Implementation Details
- **Observer**: `Observer/ReviewObserver.php` - Listens for `review_save_after` event
- **Consumer**: `Model/Queue/ReviewConsumer.php` - Processes review events via queue
- **API Endpoint**: `POST app.loyaltyengage.tech/api/v1/events`
- **Payload Format**:
  ```json
  [{
    "event": "Review",
    "identifier": "customer@email.com",
    "reviewid": "123"
  }]
  ```

### Configuration
- Admin setting: **Stores > Configuration > Loyalty Engage > Loyalty Export Settings > Review Export**
- Enable/disable review export functionality

### Files Modified/Created
- `Observer/ReviewObserver.php` (new)
- `Model/Queue/ReviewConsumer.php` (new)
- `etc/events.xml` (modified)
- `etc/communication.xml` (modified)
- `etc/queue_consumer.xml` (modified)
- `etc/queue_publisher.xml` (modified)
- `etc/queue_topology.xml` (modified)
- `etc/adminhtml/system.xml` (modified)
- `Helper/Data.php` (modified)

## 2. Free Shipping for Loyalty Levels

### Overview
Provides free shipping to customers based on their loyalty level/tier retrieved from the LoyaltyEngage API.

### Implementation Details
- **Plugin**: `Plugin/ShippingMethodPlugin.php` - Intercepts shipping rate collection
- **API Endpoint**: `GET app.loyaltyengage.tech/api/v1/contact/{identifier}/loyalty_status`
- **Logic**: Checks customer's loyalty level against configured levels and sets shipping cost to 0

### Configuration
- Admin setting: **Stores > Configuration > Loyalty Engage > Free Shipping Settings > Loyalty Levels for Free Shipping**
- Enter loyalty level names separated by semicolons (e.g., "Grand Prix;Gold;Platinum")

### Files Modified/Created
- `Plugin/ShippingMethodPlugin.php` (new)
- `etc/di.xml` (modified)
- `etc/adminhtml/system.xml` (modified)
- `Helper/Data.php` (modified)

## 3. Customizable Notification Messages

### Overview
Allows administrators to customize the text and colors of notification messages shown when loyalty products are added to cart.

### Implementation Details
- **Controller**: `Controller/Config/Messages.php` - Provides message configuration via API
- **Frontend**: Updated JavaScript files to fetch and use dynamic message configuration
- **Endpoint**: `/loyaltyengage_loyaltyshop/config/messages`

### Configuration
Admin settings under **Stores > Configuration > Loyalty Engage > Message Customization**:
- **Success Message Text**: Custom text for successful operations
- **Success Message Background Color**: Hex color for success message background
- **Success Message Text Color**: Hex color for success message text
- **Error Message Text**: Custom text for error operations
- **Error Message Background Color**: Hex color for error message background
- **Error Message Text Color**: Hex color for error message text

### Files Modified/Created
- `Controller/Config/Messages.php` (new)
- `view/frontend/web/js/loyalty-cart.js` (modified)
- `view/frontend/templates/hyva/loyalty-cart-js.phtml` (modified)
- `etc/adminhtml/system.xml` (modified)
- `Helper/Data.php` (modified)

## Configuration Summary

### New Admin Configuration Sections
1. **Review Export Settings**
   - Review Export (Yes/No)

2. **Free Shipping Settings**
   - Loyalty Levels for Free Shipping (text field, semicolon-separated)

3. **Message Customization**
   - Success Message Text
   - Success Message Background Color
   - Success Message Text Color
   - Error Message Text
   - Error Message Background Color
   - Error Message Text Color

## Installation Notes

After implementing these features:

1. **Clear Cache**: Run `php bin/magento cache:clean`
2. **Recompile**: Run `php bin/magento setup:di:compile`
3. **Deploy Static Content**: Run `php bin/magento setup:static-content:deploy`
4. **Queue Consumers**: Ensure message queue consumers are running:
   ```bash
   php bin/magento queue:consumers:start loyaltyshop_review_event_consumer
   ```

## API Integration

### Review Events
- Automatically sent when reviews are approved
- Uses existing authentication (Client ID/Secret)
- Queued for reliable delivery

### Loyalty Status Check
- Called during shipping calculation for logged-in customers
- Cached per session to avoid repeated API calls
- Graceful fallback if API is unavailable

### Message Configuration
- Loaded dynamically via AJAX
- Cached in browser session
- Fallback to default styling if configuration fails

## Compatibility

- **Magento 2.4+**: Fully compatible
- **Luma Theme**: Supported via RequireJS module
- **Hyvä Theme**: Supported via Alpine.js component
- **Queue System**: Uses Magento's built-in message queue system
- **Multi-store**: All configurations are store-scope aware

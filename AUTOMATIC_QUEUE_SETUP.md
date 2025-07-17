# Automatic Queue Processing Setup

## Overview
The LoyaltyShop module now uses Magento's standard queue processing system to automatically handle free product purchase events. No manual setup or custom cron jobs are required.

## How It Works

### 1. Queue Configuration
- **Consumer:** `loyaltyshop_free_product_purchase_event_consumer`
- **Max Messages:** 100 per batch (configured in `etc/queue_consumer.xml`)
- **Connection:** Database-based queue storage
- **Processing:** Automatic via Magento's `ConsumersRunner`

### 2. Automatic Processing
When the module is installed and enabled:
1. The `ConsumerStarter` cron job runs every minute
2. It checks if the `loyaltyshop_free_product_purchase_event_consumer` is running
3. If not running, it automatically starts the consumer in the background
4. The consumer processes up to 50 messages per batch
5. After processing, the consumer exits and will be restarted by the next cron run

### 3. Event Flow
1. **Order Completion:** Order status changes to "complete" or "accepted" with free products
2. **Observer Trigger:** `FreeProductPurchaseObserver` publishes message to queue
3. **Automatic Processing:** Magento's cron system automatically processes the queue
4. **API Call:** `FreeProductPurchaseConsumer` makes API call to LoyaltyEngage
5. **Logging:** Complete request/response details are logged

**Note:** The system supports both "complete" and "accepted" order statuses to accommodate different client workflows.

## Configuration Files

### Queue Consumer Configuration (`etc/queue_consumer.xml`)
```xml
<consumer
    name="loyaltyshop_free_product_purchase_event_consumer"
    queue="loyaltyshop.free_product_purchase_event"
    connection="db"
    maxMessages="100"
/>
```

### Queue Topology (`etc/queue_topology.xml`)
```xml
<binding id="loyaltyshop_free_product_purchase_binding" 
         topic="loyaltyshop.free_product_purchase_event" 
         destination="loyaltyshop.free_product_purchase_event"/>
```

### Communication Handler (`etc/communication.xml`)
```xml
<topic name="loyaltyshop.free_product_purchase_event" request="string">
    <handler name="loyaltyshop_free_product_purchase_handler" 
             type="LoyaltyEngage\LoyaltyShop\Model\Queue\FreeProductPurchaseConsumer" 
             method="process" />
</topic>
```

### Cron Configuration (`etc/crontab.xml`)
```xml
<!-- Consumer Starter - Ensures queue consumer is running -->
<job name="loyaltyshop_consumer_starter" instance="LoyaltyEngage\LoyaltyShop\Cron\ConsumerStarter" method="execute">
    <schedule>* * * * *</schedule> <!-- Runs every minute -->
</job>
```

**Note:** The module includes a `ConsumerStarter` cron job that automatically ensures the queue consumer is running. This provides reliable queue processing without manual intervention.

## Benefits

### 1. Zero Configuration
- Works immediately after module installation
- No manual consumer start commands required
- No custom cron job setup needed

### 2. Magento Standard
- Uses Magento's proven queue processing system
- Follows Magento best practices
- Integrates with existing queue management tools

### 3. Reliable Processing
- Automatic consumer restart if needed
- Batch processing for optimal performance
- Built-in error handling and recovery

### 4. Easy Monitoring
- Standard Magento queue monitoring applies
- Detailed logging of all operations
- Integration with Magento admin queue views

## Verification

### Check Consumer Status
```bash
php bin/magento queue:consumers:list
# Should show: loyaltyshop_free_product_purchase_event_consumer

php bin/magento queue:consumers:status
# Shows running consumers
```

### Test the Flow
1. Create an order with a free product (price = $0.00)
2. Set order status to "complete" or "accepted"
3. Check logs for the complete sequence:
   - `[LoyaltyShop] Free Product Purchase Flow Triggered`
   - `[LoyaltyShop] Starting queue consumer` (if consumer wasn't running)
   - `[LoyaltyShop] Queue consumer started successfully`
   - `[LoyaltyShop] Free Product Purchase API Request`
   - `[LoyaltyShop] Free Product Purchase API Success`

### Monitor Queue
```bash
# Check for pending messages
mysql -u [user] -p [database] -e "SELECT * FROM queue_message WHERE topic_name = 'loyaltyshop.free_product_purchase_event';"

# Check cron status
php bin/magento cron:status
```

## Troubleshooting

### If Queue Not Processing
1. **Check Cron:** Ensure Magento cron is running
   ```bash
   php bin/magento cron:status
   ```

2. **Run Cron Manually:** Force cron execution
   ```bash
   php bin/magento cron:run --group=default
   ```

3. **Check Consumer Config:** Verify consumer is properly configured
   ```bash
   php bin/magento queue:consumers:list
   ```

### If API Calls Fail
1. **Check Configuration:** Verify API credentials in admin
2. **Review Logs:** Check detailed error logs for API failures
3. **Test Connectivity:** Ensure network access to LoyaltyEngage API

## Migration from Custom Cron

If upgrading from a previous version with custom cron jobs:
1. The old `QueueConsumerManager` cron job is automatically replaced
2. No data migration required
3. Existing queue messages will be processed normally
4. Enhanced logging continues to work

## Summary

The automatic queue processing system ensures that:
- ✅ Queue consumers start automatically when the module is installed
- ✅ Messages are processed reliably without manual intervention
- ✅ API responses from LoyaltyEngage are fully logged
- ✅ The system follows Magento best practices
- ✅ No additional setup or configuration is required

The system is now production-ready and will handle free product purchase events automatically.

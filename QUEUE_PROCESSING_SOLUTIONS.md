# Queue Processing Solutions

## Current Issue
The observer is successfully publishing messages to the queue, but the consumer is not processing them automatically, resulting in no LoyaltyEngage API request/response logs.

## Solution Approaches

### Approach 1: Simple Consumer Starter (Recommended)
**File:** `Cron/SimpleConsumerStarter.php`
**Method:** Uses Magento's native ConsumerFactory to process messages directly in cron

**Benefits:**
- No shell commands or process management
- Uses Magento's built-in queue processing
- More reliable across different server environments
- Easier to debug and monitor

**Expected Logs:**
```
[LoyaltyShop] Simple Consumer Starter - Checking for messages
[LoyaltyShop] Free Product Purchase API Request
[LoyaltyShop] Free Product Purchase API Success
[LoyaltyShop] Simple Consumer Starter - Processing completed
```

### Approach 2: Shell-Based Consumer Starter (Backup)
**File:** `Cron/ConsumerStarter.php`
**Method:** Uses shell commands to start background consumer processes

**Benefits:**
- Mimics manual consumer start commands
- Runs consumer in background
- Can handle long-running processes

**Potential Issues:**
- Requires shell access and proper permissions
- May not work in all hosting environments
- Harder to debug process issues

## Current Configuration

### Active Cron Job
```xml
<job name="loyaltyshop_simple_consumer_starter" 
     instance="LoyaltyEngage\LoyaltyShop\Cron\SimpleConsumerStarter" 
     method="execute">
    <schedule>* * * * *</schedule>
</job>
```

### Queue Consumer Configuration
```xml
<consumer
    name="loyaltyshop_free_product_purchase_event_consumer"
    queue="loyaltyshop.free_product_purchase_event"
    connection="db"
    maxMessages="100"
/>
```

## Testing Steps

### 1. Test Cron System
```bash
# Check if cron is working
php bin/magento cron:status

# Run cron manually
php bin/magento cron:run --group=default
```

### 2. Test Queue Processing
```bash
# Create order with free products and set to complete
# Then check logs for SimpleConsumerStarter activity

# Check for pending messages
mysql -u [user] -p [database] -e "SELECT * FROM queue_message WHERE topic_name = 'loyaltyshop.free_product_purchase_event';"
```

### 3. Manual Consumer Test
```bash
# Test consumer directly
php bin/magento queue:consumers:start loyaltyshop_free_product_purchase_event_consumer --max-messages=1
```

### 4. Expected Log Sequence
After creating order with free products and setting to complete:

1. **Observer:** `[LoyaltyShop] Free Product Purchase Flow Triggered`
2. **Cron (within 1 minute):** `[LoyaltyShop] Simple Consumer Starter - Checking for messages`
3. **Consumer:** `[LoyaltyShop] Free Product Purchase API Request`
4. **Consumer:** `[LoyaltyShop] Free Product Purchase API Success`
5. **Cron:** `[LoyaltyShop] Simple Consumer Starter - Processing completed`

## Troubleshooting

### If No SimpleConsumerStarter Logs
**Problem:** Cron system not running or module not compiled
**Solutions:**
1. Check cron status: `php bin/magento cron:status`
2. Recompile module: `php bin/magento setup:di:compile`
3. Clear cache: `php bin/magento cache:clean`
4. Run cron manually: `php bin/magento cron:run --group=default`

### If SimpleConsumerStarter Runs But No API Logs
**Problem:** Consumer or API configuration issue
**Solutions:**
1. Test consumer manually: `php bin/magento queue:consumers:start loyaltyshop_free_product_purchase_event_consumer --max-messages=1`
2. Check API configuration in admin
3. Review consumer code for errors
4. Check network connectivity to LoyaltyEngage API

### If Messages Stuck in Queue
**Problem:** Consumer not processing or API failures
**Solutions:**
1. Check queue table: `SELECT * FROM queue_message WHERE topic_name = 'loyaltyshop.free_product_purchase_event';`
2. Clear stuck messages: `DELETE FROM queue_message WHERE topic_name = 'loyaltyshop.free_product_purchase_event';`
3. Test API connectivity manually
4. Review error logs for API failures

## Fallback: Switch to Shell-Based Approach

If SimpleConsumerStarter doesn't work, switch back to shell-based approach:

```xml
<!-- Replace in etc/crontab.xml -->
<job name="loyaltyshop_consumer_starter" 
     instance="LoyaltyEngage\LoyaltyShop\Cron\ConsumerStarter" 
     method="execute">
    <schedule>* * * * *</schedule>
</job>
```

## Manual Processing (Emergency)

If automatic processing fails completely:

```bash
# Start consumer manually and keep running
nohup php bin/magento queue:consumers:start loyaltyshop_free_product_purchase_event_consumer --max-messages=0 > /dev/null 2>&1 &

# Or process specific number of messages
php bin/magento queue:consumers:start loyaltyshop_free_product_purchase_event_consumer --max-messages=10
```

## Monitoring

### Key Log Patterns to Monitor
- `[LoyaltyShop] Free Product Purchase Flow Triggered` - Observer working
- `[LoyaltyShop] Simple Consumer Starter` - Cron processing working
- `[LoyaltyShop] Free Product Purchase API Request` - Consumer working
- `[LoyaltyShop] Free Product Purchase API Success` - API calls successful

### Queue Health Check
```bash
# Check for stuck messages
mysql -u [user] -p [database] -e "SELECT COUNT(*) as pending_messages FROM queue_message WHERE topic_name = 'loyaltyshop.free_product_purchase_event';"

# Check message age
mysql -u [user] -p [database] -e "SELECT topic_name, created_at FROM queue_message WHERE topic_name = 'loyaltyshop.free_product_purchase_event' ORDER BY created_at DESC LIMIT 5;"
```

## Summary

The SimpleConsumerStarter approach should provide reliable queue processing without the complexity of shell commands. If issues persist, the troubleshooting steps above will help identify whether the problem is with:

1. **Cron System** - Not running scheduled jobs
2. **Queue Configuration** - Consumer not properly configured
3. **Consumer Code** - Errors in processing logic
4. **API Connectivity** - Network or authentication issues

The goal is to see the complete log sequence from observer trigger through API response, confirming that LoyaltyEngage API calls are being made and logged properly.

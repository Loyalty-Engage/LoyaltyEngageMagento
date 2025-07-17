# Testing Cron Status

## Check if ConsumerStarter is Running

### 1. Check Cron Status
```bash
php bin/magento cron:status
```

### 2. Run Cron Manually
```bash
php bin/magento cron:run --group=default
```

### 3. Check for ConsumerStarter Logs
Look for these log entries in `var/log/system.log`:
- `[LoyaltyShop] Starting queue consumer`
- `[LoyaltyShop] Queue consumer started successfully`
- `[LoyaltyShop] Consumer Starter Error`

### 4. Check if Consumer Process is Running
```bash
ps aux | grep loyaltyshop_free_product_purchase_event_consumer
```

### 5. Manual Consumer Start (for testing)
```bash
php bin/magento queue:consumers:start loyaltyshop_free_product_purchase_event_consumer --max-messages=10
```

## Expected Behavior

After running cron manually, you should see:
1. ConsumerStarter logs indicating it's checking/starting the consumer
2. Consumer processing logs when messages are in the queue
3. LoyaltyEngage API request/response logs

## If ConsumerStarter Isn't Running

This could indicate:
1. Cron system not working properly
2. Module not properly installed/compiled
3. ConsumerStarter class has issues

## Alternative: Manual Consumer Test

If cron isn't working, test the consumer directly:
1. Create an order with free products
2. Set status to complete (should see observer log)
3. Run consumer manually: `php bin/magento queue:consumers:start loyaltyshop_free_product_purchase_event_consumer --max-messages=1`
4. Should see API request/response logs

This will help isolate if the issue is with:
- Cron system
- Consumer starter
- Consumer itself
- API connectivity

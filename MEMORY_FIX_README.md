# LoyaltyEngage Memory Issue Fix

## Problem
The `loyalty_order_place` cron job was consuming 28GB of RAM and causing website crashes. The issue was caused by processing ALL historical orders in the database instead of only recent orders.

## Root Causes Fixed
1. **Unbounded Query**: The cron was loading thousands of historical orders into memory
2. **Missing Date Filter**: No time-based filtering, processing orders from years ago
3. **No Batch Limits**: Processing unlimited orders per cron run
4. **Missing Database Indexes**: Slow queries on large order tables
5. **No Memory Management**: Objects not freed after processing

## Changes Made

### 1. OrderPlace.php Cron Job (`Cron/OrderPlace.php`)
- **Added 10-minute time window**: Only processes orders from the last 10 minutes
- **Added batch size limit**: Maximum 100 orders per run as safety measure
- **Added comprehensive logging**: Track performance and memory usage
- **Added error handling**: Prevent crashes and infinite retries
- **Added memory cleanup**: Free objects after processing
- **Skip configurable child items**: Avoid duplicate product processing
- **Added validation**: Skip orders without email or products

### 2. Database Schema (`etc/db_schema.xml`)
- **Added indexes** on `loyalty_order_place` column
- **Added indexes** on `loyalty_order_place_retrieve` column  
- **Added composite index** on `loyalty_order_place`, `loyalty_order_place_retrieve`, and `created_at`
- Applied to both `sales_order` and `sales_order_grid` tables

## Expected Results
- **Memory usage**: Reduced from 28GB to under 100MB
- **Execution time**: Reduced from minutes to seconds
- **Query performance**: Significantly faster with proper indexes
- **Reliability**: Better error handling and logging

## Deployment Instructions

### 1. Apply Database Schema Changes
```bash
php bin/magento setup:upgrade
php bin/magento setup:db-schema:upgrade
```

### 2. Clear Cache
```bash
php bin/magento cache:clean
php bin/magento cache:flush
```

### 3. Recompile (if needed)
```bash
php bin/magento setup:di:compile
```

### 4. Monitor the Cron
Check logs to verify the fix is working:
```bash
tail -f var/log/system.log | grep "LoyaltyEngage OrderPlace"
```

## Monitoring
The cron now logs detailed information including:
- Number of orders processed
- Success/error counts
- Execution time
- Memory usage
- Time window being processed

Look for log entries like:
```
LoyaltyEngage OrderPlace cron completed: processed_count: 5, success_count: 5, error_count: 0, execution_time: 2.3s, memory_usage: 45.2MB
```

## Configuration
The time window is currently set to 10 minutes and can be adjusted in the `OrderPlace.php` file by changing the `TIME_WINDOW_MINUTES` constant.

## Rollback Plan
If issues occur, you can temporarily disable the cron by commenting out the job in `etc/crontab.xml`:
```xml
<!-- Temporarily disabled
<job name="loyalty_order_place" instance="LoyaltyEngage\LoyaltyShop\Cron\OrderPlace" method="execute">
    <schedule>*/5 * * * *</schedule>
</job>
-->
```

## Historical Orders
Orders placed before the fix will not be automatically processed. If you need to process historical orders, create a separate one-time script or temporarily increase the `TIME_WINDOW_MINUTES` value.

# LoyaltyShop Queue Processing Setup

## Overview

The LoyaltyShop module uses Magento's message queue system to process events asynchronously, ensuring no impact on webshop performance.

## Queue Consumers

The following queue consumers are used:

| Consumer | Purpose |
|----------|---------|
| `loyaltyshop_free_product_purchase_event_consumer` | Sends free product purchase events to LoyaltyEngage API |
| `loyaltyshop_free_product_remove_event_consumer` | Sends cart remove events to LoyaltyEngage API |

## Automatic Configuration

When the module is installed, it automatically configures the `cron_consumers_runner` in `app/etc/env.php` to process these consumers.

## Requirements

### 1. Magento Cron Must Be Running

For queue processing to work, Magento cron must be running on your server. Add this to your server's crontab:

```bash
* * * * * /usr/bin/php /path/to/magento/bin/magento cron:run >> /var/log/magento.cron.log 2>&1
```

Or for Docker environments:
```bash
* * * * * docker exec -t <container_name> php bin/magento cron:run
```

### 2. Verify Cron is Running

Check if cron is running:
```bash
bin/magento cron:status
```

Check cron schedule:
```bash
bin/magento cron:run --group=default
```

## Manual Configuration (if needed)

If the automatic configuration didn't work, add this to `app/etc/env.php`:

```php
'cron_consumers_runner' => [
    'cron_run' => true,
    'max_messages' => 100,
    'consumers' => [
        'loyaltyshop_free_product_purchase_event_consumer',
        'loyaltyshop_free_product_remove_event_consumer'
    ]
]
```

## Manual Queue Processing

To manually process queues (for testing):

```bash
# Process all messages for a specific consumer
bin/magento queue:consumers:start loyaltyshop_free_product_purchase_event_consumer --max-messages=10

bin/magento queue:consumers:start loyaltyshop_free_product_remove_event_consumer --max-messages=10
```

## Troubleshooting

### Queue messages not being processed

1. **Check if cron is running:**
   ```bash
   bin/magento cron:status
   ```

2. **Check cron_consumers_runner config:**
   ```bash
   grep -A 10 "cron_consumers_runner" app/etc/env.php
   ```

3. **Check queue table for pending messages:**
   ```sql
   SELECT * FROM queue_message_status WHERE status = 2;
   ```

4. **Check logs:**
   ```bash
   tail -f var/log/system.log | grep LoyaltyShop
   ```

### Force process queues now

```bash
bin/magento cron:run --group=consumers
```

Or run the SimpleConsumerStarter cron directly:
```bash
bin/magento cron:run --group=default
```

## Queue Processing Frequency

By default, the `SimpleConsumerStarter` cron job runs every 5 minutes and processes up to 10 messages per consumer per run.

This can be adjusted in `etc/crontab.xml` if needed.

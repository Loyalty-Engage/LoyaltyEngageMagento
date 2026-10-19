# Local Release Review, 2026-10-19

## Decision

The new settings and the minimum-spend, completed-cycle recovery, indexing and shipping fixes pass local regression checks and live demo acceptance. Deployment configuration is no longer written by setup patches. The maintainer accepted the separate REST order-origin finding as non-blocking and authorized release after live acceptance. Release target: 2.6.4. See LIVE_ACCEPTANCE_2026-10-19.md for the live evidence and environmental limits.

## Verification

Environment: Magento Open Source 2.4.8, PHP 8.3.16, PHPUnit 10.5.48.

| Check | Result |
| --- | --- |
| Unit tests | 131 tests, 380 assertions passed |
| Magento runtime tests | 40 tests, 251 assertions passed |
| Native tier-price tests | 21 tests, 42 assertions passed |
| Frontend regressions | 6 tests passed |
| Total | 198 tests; 673 PHP assertions |
| DI compilation | Passed |
| PHP/PHTML syntax | Passed, excluding gitignored manual runners |
| Changed configuration XML/XSD | Passed |
| Composer validation | Passed; container has no Git metadata, so Composer reports its fallback package version |
| Database status | All modules up to date |
| Git whitespace check | Passed |
| Login page | HTTP 200 |
| Both cart/add route aliases, guest | JSON HTTP 401 |
| Customer-update REST route, no credentials | JSON HTTP 401 |

The combined runtime test exercises real Magento models, store-scoped configuration and the actual outbox publisher. Two stores use different tax bases; tier-excluded lines are absent from both Purchase and two partial Returns; coupon discount fields do not cause exclusion; creditmemos can fall back to the historical order unit price; repeated publication is deduplicated; a configuration change does not overwrite an already queued Purchase. Database fixtures are rolled back and outbound transport is mocked. An initial test-fixture failure used Magento's specialized CreditmemoFactory as a generic factory; it was corrected before the passing run.

The upgrade dry run emitted only two indexes on loyaltyshop_mutation (status/available_at and customer_id/operation/sku/status). The actual local upgrade succeeded and EXPLAIN selected both indexes. No sales_order or sales_order_item DDL was emitted. This is not a production-sized benchmark.

## Open Findings

### High: Storefront REST checkout is classified as an API import

`Model/OrderSource.php::fromAreaCode()` classifies every `webapi_rest` and `graphql` order as API. `Observer/CaptureOrderSourceObserver.php` uses only the area. Magento's own `Magento_Checkout/view/frontend/web/js/action/place-order.js` places normal logged-in and guest orders through REST payment-information endpoints. Consequently, a merchant selecting only Storefront can unintentionally exclude normal web orders. Current tests codify the area mapping and do not prove the merchant-facing origin distinction. Separate shopper checkout from integration imports using trusted origin/context; do not use an arbitrary client header as proof.

## Resolved Findings

### Minimum-spend enforcement did not cover quantity-only reductions

Quote totals now enforce the store-scoped minimum with a recursion guard and one additional totals pass after removal. Submission forces fresh totals and persists removals before asking the shopper to review the cart. A native Magento test reduces paid quantity, preserves the paid line, removes the reward, and proves repeated collection/save produces one removal event. Unit tests cover submission and bounded recollection. No full browser checkout was performed.

### Completed reservation cycles blocked later recovery

Exact applied cycles with a matching sent removal are now marked released. Six runtime cases cover successful retirement and blocked pending/failed removals, unknown keys and uncertain/received journals. Recovery never replays the remote purchase. The bounded history lookup remains conservative for older unprovable cycles.

### Recovery journal queries lacked supporting indexes

Added module-owned due/conflict indexes and bounded per-status candidates, total processing and between-record runtime. Local EXPLAIN checks use the indexes. Production-volume latency and rows examined still require merchant workload validation.

### Shipping plugin repeatedly invalidated collected rates

Eligibility is evaluated once per collection. Unchanged qualification no longer forces carrier collection; eligibility transitions and Magento invalidation still do. Five added unit cases cover these decisions. Carrier latency/load was not benchmarked.

### Setup patches changed deployment configuration

ConfigureCronConsumers is retained as a no-op for patch-history compatibility. UpgradeEventDelivery no longer invokes it. Runtime tests verify env.php remains byte-for-byte unchanged. Existing settings are not reverted; deployment retains ownership. README documents cron and the six legacy DB consumers without starting them or changing AMQP configuration.

## Operational Notes

- The local nginx container was stopped and its included `/var/www/html/nginx.conf` was empty. It was started and that empty file was replaced with the existing Magento `nginx.conf.sample`; nginx validation and reload passed. This is a local container repair, not a module change. The login and authorization HTTP checks then passed.
- The initial automated checks used mocked transport. Subsequent authorized live acceptance exercised actual tenant calls, two browser checkouts, outbox delivery and expiry directly. The existing local RabbitMQ container was started because Magento inventory reservations require it. No worker daemon or scheduler configuration was changed. Offline payments were used and order emails were temporarily disabled.
- Inclusive/exclusive tax is selected at event creation. Already delivered/queued events retain their payload. A setting change can cause a later Return to use a different basis from an older Purchase; coordinate the cutover with Loyalty Engage. If historical gross prices are unavailable, the documented fallback retains the existing exclusive unit price rather than guessing a tax rate.
- Validate on the merchant's actual Magento version, theme, checkout extensions, tax configuration and workload before deployment. Demo acceptance does not resolve the known order-origin finding or provide a production-volume benchmark.

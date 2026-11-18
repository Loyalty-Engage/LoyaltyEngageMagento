# Production Readiness Review

## Checkout Attribute Containment, 2026-11-18 (2.6.8)

The seven internal customer fields are not customer-editable inputs. Fresh-install patches now set
`visible = false`; an upgrade patch changes only matching customer EAV metadata and removes non-admin
form assignments in one transaction. It retains the attribute IDs, types, system/user-defined flags,
all legacy customer values and store-scoped balances. It neither changes schema nor accesses sales tables.
Database failures propagate and roll back. Both EAV and customer metadata caches are invalidated.

The frontend-only plugin filters `CustomerMetadataInterface::getAttributes()` (form metadata), including
cached form results. It does not filter address metadata, `getAllAttributesMetadata()` or
`getCustomAttributesMetadata()`. No plugin is registered in adminhtml, REST or GraphQL. This protects
Magento's standard form extraction as well as rendering; it is not a universal firewall for custom
checkout code that bypasses Magento metadata or writes arbitrary customer attributes directly.
It adds no database queries or remote calls to form rendering or order placement.

Known UI impact: generic customer-admin EAV inputs also become hidden. The module's account/Page Builder
presentation uses its data provider, not generic EAV visibility, and retains its own per-store visibility
settings. This change does not turn off synchronization, earning, enrollment or wallet features.

### No-update containment

`Test/Manual/hide-loyalty-checkout-fields.sql` is an explicit manual equivalent of the metadata repair,
not an automatic deployment step. Back up the selected metadata/form-assignment rows first, inspect the
seven matched customer attribute codes, adjust any Magento table prefix, and test on staging. Execute
the statements transactionally against the affected Magento database. If any statement fails, roll back
instead of continuing to COMMIT. This script does not add the runtime frontend filter.

Then run `bin/magento cache:clean config eav layout block_html full_page`, close existing checkout tabs,
and open a fresh checkout. Check both shipping and billing forms with a guest and a logged-in member.
Do not rely on CSS hiding. Do not modify `system` or `user_defined` to hide fields: that may remove legacy
attributes from repository custom-attribute data. Do not use the Hyva restore-attributes command as a fix;
it restores old configuration, not just these loyalty fields.

### Release gate

Automated coverage includes fresh creation, scoped metadata selection with table prefixes, empty selections,
failure rollback, repeat/no-op updates, both cache invalidations, stale form metadata, extraction of forged
loyalty input through the native Magento form, normal field preservation, frontend-only DI and native Magento
custom-attribute metadata retention. Full PHP unit suite and PHP/XML validation are run locally.

Local result on PHP 8.4.6: 219 unit tests / 78,396 assertions passed, including nine focused tests for this
change. PHP syntax checks, frontend DI XML validation against Magento's schema (with its URN resolver)
and `git diff --check` passed. These are not live database/checkout results.

Merchant staging checklist (the local evidence and remaining gaps are recorded below). Run against the
merchant's installed checkout/customizations; unit tests are not a substitute:

- Back up the seven attribute definitions, all their form assignments and fixture-customer balances.
- Verify a clean installation and an upgrade from 2.6.7, including `setup:upgrade` and DI compilation.
- Apply the repair twice and verify no additional changes on the second run; compare balances and attribute IDs.
- Inspect shipping and separate billing forms, guests and logged-in customers, initial render and AJAX re-render.
- Submit forged values for the seven fields and verify none are saved by the installed checkout's save services.
- Verify normal address fields, address saves and full checkout/order placement still work.
- Verify REST updates, account/Page Builder display, legacy EAV fallback and per-store balance isolation.
- Confirm the generic customer-admin fields are hidden, as documented; verify other customer fields remain editable.

Environment recovery, 2026-11-18: Docker Desktop starts outside the sandbox; the earlier conclusion that
the app was absent was incorrect. Hyva packages were recovered from the existing Docker volume, not the
host vendor folder: Theme 1.5.3 and Checkout 1.3.15 on Magento 2.4.8 / PHP 8.3.36. Missing temporary
PHP-FPM/Nginx configs and the local TLS certificate were recreated. The Docker disk was full; removing
only unused build cache older than seven days reclaimed 3.826 GB without removing images or data volumes.
All four demo services are running again. About 1 GB remains available inside the VM; increase storage
headroom before extensive further tests.

A private local database/config backup was taken before running setup:upgrade. Upgrade and DI compilation
passed, and setup:db:status reports all modules up to date. All seven customer attributes changed from
visible to invisible; selected form assignments and selected configuration were unchanged. Balance tables
were empty in this demo snapshot, so matching before/after hashes are NOT evidence of populated-balance
preservation. The full suite also passed inside the container: 219 tests / 78,396 assertions on PHP 8.3.36.
Both /default/ and /hyva/ returned HTTP 200 over HTTPS with explicit trust in the local test certificate.

### Completed local acceptance

The regenerated local certificate was accepted by the user. Browser acceptance ran on the actual Luma
and Hyva stores, with fictional customer 48 and distinct balances in store 1 and store 2. Temporary
per-store account-display configuration was restored and verified against the original rows afterwards.
Fictional profiles, addresses and test orders remain in the demo for audit; the test browser is logged out.

| Store / customer | Order | Shipping / billing | Result |
| --- | --- | --- | --- |
| Luma guest | 000000220 | New shipping and separate billing | Success |
| Luma member | 000000221 | New saved address, same billing | Success |
| Hyva member | 2000000093 | New saved shipping and separate saved billing | Success |
| Hyva guest | 2000000094 | New shipping and separate billing | Success |

All four orders were placed through the browser with the demo product, EUR 25 plus EUR 5 flat-rate
shipping, and offline `checkmo` payment. Database inspection confirmed `pending` status, the correct
store/guest/customer assignment, totals and persisted shipping/billing addresses. Hyva entry through the
minicart flyout, country-dependent address re-rendering and separate billing forms were exercised.
None of the seven loyalty inputs appeared. All seven read-only account values remained correct in each
store (including points 1201/1202 and available coins 2301/2302); the full stored balance rows were unchanged.

A transaction-isolated native fixture seeded populated legacy EAV values and deliberately restored old
visibility/form mappings, then applied the repair twice. Nineteen checks passed: cached frontend filtering,
normal customer fields, metadata flags and form assignments, exact preservation of populated legacy and
store-scoped balances, custom-attribute metadata retention, per-store provider reads, and actual Hyva
shipping/billing field mappings. Fixture mutations were rolled back. The same 19 checks passed again
after the additional account redirect fix below.

Acceptance found a pre-existing guest account failure: Magento's account authentication plugin returns
null after setting a login redirect, but `Controller/Account/Index::execute()` required ResultInterface.
Its generated interceptor threw a TypeError. The return type is now nullable, with two regression tests.
DI compilation and the complete suite passed again: 221 tests / 78,402 assertions on PHP 8.3.36.
Both themes now redirect guests to login; logging in still renders the proper account/store balances.
The Hyva guest order was completed after this fix. The other three orders preceded this isolated return-type
fix and were not placed a second time. `setup:db:status` and `git diff --check` also passed.

No new outbox events or redemption mutations were recorded during the browser run. No real coins or wallet
funds were spent. Logs after the redirect repair showed no new module errors, but the demo has unrelated
Braintree missing-merchant-ID errors and order-email transport errors because the `mailcatcher` host is
absent. Orders were persisted despite those email errors; email delivery and online PSP flows are NOT
verified. The test account's populated values are fixtures, not a live tenant sync.

This first acceptance run validates the checkout-field repair and ordinary checkout on the installed versions, not every
module feature or every Hyva/custom checkout version. A full clean-install Magento deployment, live REST
sync/Page Builder, checkout-specific forged-save requests, wallet/provider callbacks and a production-volume
load test were not repeated here. Native Magento form extraction/metadata protections have automated
coverage, but custom save services that bypass that metadata require separate review. Merchant ACC
acceptance remains required. These results were recorded before publishing 2.6.8.

### Extended module regression, 2026-11-18

The subsequent full-module test pass is documented in
`Test/Reports/2.6.8-acceptance-2026-11-18.md`. It includes 222 unit tests / 78,410 assertions,
25 frontend tests, native order/cart/pricing tests, native wallet financial/concurrency tests and
285 real Magento configuration-save checks across default, website and store scopes.
Two native cases needing removed pre-2.6.2 sales-order columns were skipped, not passed.

Real admin saves and browser checks exercised enrollment on both installed themes, store-specific labels
and modes, membership gating, wallet application/removal and existing Page Builder personalization rendering.
This uncovered a wallet preview persistence bug when Magento skipped an unchanged quote's save event.
The Apply controller now explicitly saves its collected preview after repository save. Unit regressions
cover application and removal even without a save event; both checkout totals were verified after the fix.
This adds a bounded update on the module-owned wallet quote row only when applying/removing wallet credit,
not on ordinary order/grid loads. No financial policy or schema was changed.

DI compilation, final unit/native/frontend reruns and schema status passed. All six REST routes rejected
unauthenticated local HTTP requests with 401. All temporary loyalty configuration and fictional customer
balances were restored exactly, the wallet selection is zero, and no new outbox/redemption mutations remain.
Online PSP callbacks, email delivery, fresh real tenant purchases, every historical upgrade variant,
third-party checkout overrides and production-volume performance are not certified by this local test pass.
This verification provides evidence for release 2.6.8; it does not replace merchant-specific acceptance.

## Release 2.6.4 Verification, 2026-10-19

Purchase and Return now share the store-scoped `loyalty/export/event_price_includes_tax` selector, defaulting to exclusive tax. Inclusive mode uses stored historical unit prices in order currency; creditmemos fall back to their original order item. Discount handling and queued payloads remain unchanged. The combined Magento/outbox test covers both tax bases across two stores, tier exclusion, partial refunds, deduplication and immutable queued payloads.

Current verification: 131 unit tests / 380 assertions, 40 runtime tests / 251 assertions, 21 native pricing tests / 42 assertions and six frontend tests passed (198 tests total). Coverage includes quantity-only minimum-spend enforcement, exactly-once removal persistence, completed reservation cycles and unchanged env.php. Upgrade SQL adds only two indexes on loyaltyshop_mutation; the local upgrade succeeded and EXPLAIN selected those indexes. This is not a production-volume benchmark. No live tenant mutations were sent.

Live demo acceptance completed for this release: two browser checkouts, native tier/coupon filtering, partial refunds, physical and coupon redemption, minimum enforcement, expiry, review, scoped updates and controlled lost-response recovery. Fifteen outbox events were accepted on their first attempt; no test reservations remain. Temporary configuration, tier prices and theme were restored. See `LIVE_ACCEPTANCE_2026-10-19.md` for evidence and limits. The maintainer accepted the remaining order-origin finding as non-blocking for this release. Older dated verification sections below are historical evidence.

Review date: 2026-09-24. Live acceptance follow-up: 2026-09-27/28. Release target: 2.6.1 (2026-09-29); existing release tags are unchanged.

## 2.6.3 Verification

Follow-up fixes first-save item persistence, legacy flag initialization and source precedence. Magento 2.4.8 / PHP 8.3 validation passed: 99 unit tests (244 assertions), 30 runtime tests (187 assertions), six frontend tests and DI compilation. New runtime cases save and reload an actual order with its items and verify legacy flag preservation. Runtime fixtures roll back database changes and mock outbound transport.

The order-state observer performs no item loads or writes without a newly captured source. A unit test verifies that 100 unresolved items require two marker queries. This is a query-count check, not a production-volume or concurrency benchmark.

Installations that already processed orders with 2.6.2 must inspect affected source/flag rows before historical replay. This fix prevents further overwrites but cannot infer lost source values or prove remote delivery. No automatic bulk repair is run. Keep the dedicated tables on rollback; older code reading only legacy columns cannot see newer state, so delivery state must be reviewed before reverting or replaying events.

## Confirmed Findings and Fixes

### Unreleased Quantity-Tier Export Filter, 2026-10-18

Added the opt-in store-scoped `loyalty/export/exclude_tier_priced_items` setting (default No). Magento's native product price models are compared with and without quantity tiers above one at quote-to-order conversion. Only an actual price reduction attributable to those tiers is excluded; coupon/cart-rule discounts alone do not qualify. The per-line decision is stored in existing `product_options` and used by Purchase and Return without repricing historical orders or adding sales-table columns. The disabled path performs no tier-price calculations.

Verification on local Magento 2.4.8 / PHP 8.3.16: 114 unit tests / 306 assertions, 31 existing runtime tests / 193 assertions, 21 native-pricing runtime cases / 42 assertions, six frontend tests, and full DI compilation passed. Coverage includes thresholds, customer groups, database-loaded percentage tiers, website isolation, special/catalog-rule prices, coupon-only versus coupon-plus-tier discounts, configurables, fixed/dynamic bundles, the registered conversion plugin, save/reload persistence, mixed orders, same-SKU lines and partial refunds. Runtime fixtures roll back and no live tenant mutations are sent.

This is not a coupon/coin stacking restriction. It applies to new orders going through Magento quote conversion; historical orders, already queued events and direct imports without conversion retain existing behavior. Third-party pricing/checkout overrides and representative merchant load still require staging acceptance. Pricing inspection failures log a warning and leave the line eligible rather than breaking checkout or guessing an exclusion. This feature verification does not constitute a new full-module production sign-off.

### Large Sales Tables

The module no longer declares or writes loyalty columns on `sales_order`, `sales_order_item`, `sales_order_grid` or `sales_creditmemo`. New state lives in `loyaltyshop_order_data`, `loyaltyshop_order_item_data` and `loyaltyshop_creditmemo_data`, each keyed to its Magento entity with a foreign key and cascade delete. Existing legacy columns are read only for the individual entity being processed when the new row does not exist; there is no bulk `INSERT ... SELECT` migration during `setup:upgrade`. Leaving the dedicated tables in place avoids a schema rollback, but code rollback requires the delivery-state review described above.

Before production deployment, review the actual upgrade SQL on ACC for the installed module set: no ALTER or DROP on sales_order or sales_order_item should be introduced by this module. Existing declarations still extend quote_item, quote_address and salesrule; assess their upgrade cost on the merchant database. Foreign-key creation can acquire metadata locks. No production-size load benchmark has been performed.

| Priority | Finding | Resolution |
| --- | --- | --- |
| Blocker | The automatic Brons rule used an invalid `free_shipping` calculator and could break totals. | New rules are inactive, use `by_percent` with zero discount and address free shipping. A separate repair patch corrects already-installed invalid rules. |
| High | Events were acknowledged or marked exported before a confirmed API result, with missing consumers and duplicate delivery paths. | A transactional outbox replaces new broker publishing. Delivery flags are written after successful delivery. Failed events remain inspectable and retryable. Old consumers transfer their messages to the outbox. |
| High | Return lacked `orderId`, repeated creditmemo saves could duplicate it, and it included configurable child rows. | Per-creditmemo identity, order linkage, refunded-state validation, matching item calculation rules, and delivery after the creditmemo commits. |
| High | Outbound mutations could spend coins before a failed local save; repeat requests could spend again. | Confirmed responses are journaled and reused. Local recovery never sends a second purchase/reservation request. Ambiguous responses require reconciliation. |
| High | Adding a free item could merge into an existing paid line; remove-all could remove paid items. | Loyalty markers are added before quote merging and copied to the order. Removal, expiry and redemption operate on explicit loyalty items only. |
| High | Partial or cross-store customer updates could overwrite other balances or leak fallback values. | Partial-column writes, explicit target-store authentication, quote/website checks, and no global mirroring or cross-store legacy fallback. |
| High | Existing plaintext credentials were decrypted without migration; REST authentication could return HTTP 500 or fail open. | Idempotent encryption patch plus legacy reads; authorization inside Magento's API exception boundary and a private ACL on all six routes. |
| High | Cart coupon hooks handled merchant coupons, while generated coupon rules could be reused across websites. | Explicit ownership marker, website-qualified rule lookup, conflict rejection and ownership checks on claim/redemption. |
| Medium | Order exports could not distinguish import/admin orders or select several custom statuses. | Store-scoped source allowlist, comment exclusions, persisted origin and a multiselect of Magento's available status codes. Defaults continue to allow every source. |
| Medium | Session/localStorage snapshots overwrote freshly synchronized account balances. | One conditional customer-section reload; Hyva cache session invalidation before reload; guest events clear old metadata. |
| Medium | Private or disabled metadata could appear in a cacheable block or be reintroduced by JS. | No server-side customer snapshot on cacheable layouts; enabled-field allowlist, HTML escaping and CSP-aware script rendering. |
| Medium | Free shipping depended on a session or specific carrier plugins. | Qualification uses the quote customer/store, applies to collected shipping rates and recollects rates when eligibility changes. |
| Medium | A reused HTTP client retained PUT/DELETE settings, and invalid API responses could be marked successful. | Reset method and headers on each request; no redirect forwarding; rejected, HTML and zero-accepted-event responses are visible failures. |
| Medium | Catalog rules attempted to index customer-specific loyalty values globally. | Offer loyalty conditions only in cart price rules; the old catalog condition remains loadable but no longer evaluates customer sessions during indexing. Review existing catalog rules before reindexing. |

## Delivery and Recovery Contract

- New events are saved in `loyaltyshop_outbox`, not in Magento's broker tables. A quote/order/creditmemo transaction rolls back its outbox records if the local save rolls back. Do not diagnose missing new events by looking only at `queue_message`.
- `loyaltyshop_deliver_events` runs in the default Magento cron group every minute. It uses a process lock, a bounded batch and a time budget. Network/429/5xx failures back off, with at most ten attempts. Permanent errors remain `failed`; missing configuration, disabled export or a pending Purchase defer without consuming an attempt.
- Event identities include store and order/creditmemo/review/removal IDs. A resave cannot create another event with the same identity. A removal followed by a later re-add has a distinct reservation cycle.
- Exported order and creditmemo flags mean delivery succeeded, not merely that a message was queued. Changing status to another selected status does not grant a second Purchase.
- New Returns wait for their tracked Purchase. For orders predating `loyalty/export/tracking_started_at`, the module can only infer eligibility from the old flag, status/history, enabled export and source policy. Old releases did not record reliable delivery receipts. Reconcile historical orders against Loyalty Engage before a bulk refund/backfill; this compatibility fallback is not proof of remote delivery.
- Delivery is **at least once**, not a distributed exactly-once transaction. Every request includes an `Idempotency-Key`. If Loyalty Engage accepts a request and the connection or local acknowledgement then fails, a retry can reach the service again. Production acceptance must confirm server-side deduplication, including distinct partial refunds of one order. A response reporting zero accepted events is retained as a failure for reconciliation, not silently treated as delivery.
- Confirmed cart/coupon responses are stored in `loyaltyshop_mutation`. The recovery cron applies those responses to the original active cart. It never spends coins again. An inactive/changed cart eventually requires manual recovery. Timeouts and unconfirmed responses are deliberately not repeated automatically. Missing credentials detected before transport do not block a later retry after configuration is repaired.
- Correction in 2.6.1: an HTTP 400 with one of the documented redemption `message` codes is a definitive refusal. It is recorded as `rejected`, returns HTTP 400 with a readable message and a specific `error_type`, and permits a later customer retry without a profile reset. The recovery cron never automatically retries rejected purchases. Unknown/malformed responses and transport/server failures remain `uncertain`. Older uncertain records lack a verified refusal reason and are not automatically migrated or released by a timer. An uncertain record blocks that operation key, not the customer's profile or normal paid checkout. Automatic resolution requires a remote result lookup or a verified server-side idempotency contract; elapsed time alone does not establish that a reservation failed.
- One coupon purchase is reused per cart/SKU. A product cannot be re-added until its preceding removal has synchronized. This prevents a delayed removal from cancelling a newer reservation.
- Loyalty-only expiry uses the item's age and the store's configured lifetime. Expiry and cart removal preserve ordinary paid lines.

## Deployment

1. Back up the database and configuration. Test the release candidate on staging using the merchant's themes, enabled modules and PHP version. Drain or stop existing queue workers during code deployment; do not leave workers running old PHP code.
2. Enable maintenance mode, deploy the module and run `bin/magento setup:upgrade`. Review the actual schema changes on ACC first. Named patches repair old shipping rules, encrypt legacy credentials, mark generated coupon rules and establish the event-tracking cutover. Consumer-runner settings and env.php are not modified. The historical ConfigureCronConsumers patch is a no-op and UpgradeEventDelivery no longer invokes or depends on it. Follow the README's Cron and Consumer Ownership section for deployment-managed scheduling and legacy DB backlog processing.
3. Run `bin/magento setup:di:compile`, deploy static assets for the actual themes/locales, then `bin/magento cache:clean`. Keep health checks and concurrent requests from regenerating code during compilation. Do not delete business data or event records to clear caches.
4. Disable maintenance mode and resume the normal Magento cron/workers. `bin/magento setup:db:status` must report that modules are up to date.
5. Check `bin/magento loyalty:events` and confirm the new cron jobs run successfully in `cron_schedule`. Review pending age, failure counts and processing throughput for the merchant's actual order volume.
6. Inspect previously configured catalog price rules using the old loyalty condition. Replace those with cart price rules before reindexing; a globally indexed catalog rule cannot safely depend on the current customer's balance.

The corrected Brons sales rule is intentionally inactive. Merchants choose whether to enable their own rule or the existing store-scoped `loyalty/shipping/free_shipping_enable` / `free_shipping_tiers` configuration. The module does not automatically grant free shipping to every website.

For a merchant excluding API and admin orders, select `storefront` in **Order Event Export Sources**. Include `unknown` only when wanted for older/unclassified orders. Optional comment markers are case-insensitive substrings and must exist when the trigger status is saved; a comment added after delivery cannot retract a Purchase. Select one or more exact statuses in **Purchase Sync Order Status**. Unconfigured merchants retain the defaults.

Both storefront route prefixes (`loyalty/` and `loyaltyshop/`) remain valid. REST callers must use the intended store context and that store's credentials. New HTTP 4xx/5xx responses must be handled as errors by clients rather than treated as successful API calls.

Only `customer/update` supports an explicit `storeCode` (or `store_code`). Authorization uses Magento's merged request data and the same parameter precedence as its input processor, including query parameters. Cart routes use the store from the REST URL and reject an explicit target-store parameter. Composer version metadata now comes from Git tags instead of a hardcoded version in `composer.json`; existing published tags are unchanged.

## Operator Commands

Run commands from the Magento root. Fix the cause and reconcile the remote outcome before replaying an event.

```sh
bin/magento loyalty:events
bin/magento loyalty:events --retry=<failed-event-id>
bin/magento loyalty:events --retry=<ambiguous-event-id> --store=<store-code>
bin/magento loyalty:events --import-message=<legacy-db-message-id>
bin/magento loyalty:events --retry-mutation=<confirmed-operation-key>
```

- `--retry` only requeues failed events; it does not automatically replay delivered records.
- A legacy payload with no safely resolvable store is held as failed. `--store` resolves it explicitly rather than sending it with another tenant's credentials.
- Legacy DB messages can be copied individually into the outbox. Processing, completed and scheduled-for-deletion messages are rejected. An old error status may represent an unknown remote outcome: reconcile before importing. Legacy consumers can drain normal pending messages; the same event identity deduplicates overlapping imports.
- `--retry-mutation` only re-applies a confirmed response. It cannot safely retry an uncertain purchase. For `started`/`uncertain` records, first resolve the remote reservation/coupon against the recorded operation key with Loyalty Engage. Do not delete the record and click redeem again as a workaround.
- Outbox payloads contain identifiers; the journal can contain coupon codes. Restrict database/backup access and establish a retention policy. Keep deduplication records for the merchant's replay/refund horizon; blindly purging them re-enables duplicate delivery.

## Verification

Local environment: Magento Open Source 2.4.8, PHP 8.3.16, PHPUnit 10.5.48, two store views. Luma and Hyva are installed. No real external mutations are sent by the tests.

Final local results on 2026-09-24:

| Check | Result |
| --- | --- |
| PHPUnit unit suite | 50 tests, 82 assertions passed |
| Magento runtime integration suite | 16 tests, 80 assertions passed |
| JavaScript regressions | 6 tests passed (Luma and Hyva section flows) |
| PHP/PHTML syntax | 121 files valid |
| XML/XSD validation | 25 files valid; corrected five obsolete/invalid schema URNs |
| Composer | `composer validate --strict` passed |
| Database upgrade/status | Upgrade succeeded; all modules up to date |
| Full dependency injection compilation | Passed; the local web healthcheck was paused during compilation and resumed afterward |
| Coding standard severity 8+ | No errors; two reviewed warnings for the pure static event-key function and strict Basic Auth base64 decoding. This is not a claim that all lower-severity formatting sniffs pass. |
| HTTP storefront | Homepage 200, metadata bootstrap present, empty server-side guest snapshot |
| HTTP storefront aliases | All four cart/add and discount/claim routes returned JSON/401 for a guest |
| HTTP REST authorization | All six REST routes returned JSON/401 for invalid credentials |
| Queue compatibility | All six legacy consumers registered; outbox diagnostic command functional |
| Final environment | Caches cleaned, maintenance disabled, webcontainer resumed |

At the 2026-09-24 review, no authenticated full-browser checkout or real Loyalty Engage acceptance test had been performed. Frontend JavaScript regressions use a DOM/event harness. See the subsequent live acceptance results below.

From the Magento root:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap dev/tests/unit/framework/bootstrap.php app/code/LoyaltyEngage/LoyaltyShop/Test/Unit
php vendor/bin/phpunit --no-configuration app/code/LoyaltyEngage/LoyaltyShop/Test/Integration/RuntimeTest.php
node --test app/code/LoyaltyEngage/LoyaltyShop/Test/Frontend/loyalty-meta-bootstrap.test.cjs
```

The runtime suite is a local/staging smoke suite, not a production command. It requires sample SKU `24-MB01`, an existing customer and two store views; all database fixture changes are rolled back. It uses a mocked API transport and isolates existing pending events within the test transaction. Do not run it against production.

Coverage includes source/comment exclusions, multistatus selection, private REST authorization, same-origin AJAX/CSRF, retry and terminal failures, zero accepted events, real outbox persistence, legacy message transfer, refund ordering, coupon ownership/website scope, paid and free lines sharing a SKU, store-scoped partial writes, shipping qualification, encrypted-config repair and frontend stale-cache/logout/escaping behavior.

Before production sign-off, perform a staging acceptance run against a test Loyalty Engage tenant: purchase, two partial refunds, coupon redemption, free-item removal/expiry, connection interruption and retry, and balance updates in both the merchant's Luma and Hyva flows. Confirm remote idempotency and historical reconciliation, then run a representative order-volume test. Local passing tests and DI compilation do not prove those external contracts or every third-party checkout integration. Publishing 2.6.0 does not replace merchant-specific acceptance.

## Live Acceptance Follow-up, 2026-09-27/28

- Real tenant calls confirmed Purchase delivery from both store views, two separate partial Returns, approved Review delivery, physical reservation and final redemption, storefront removal, loyalty remove-all and expiry. Paid/free lines sharing a SKU remained separate and paid items survived removal. Including the replacement coupon order, eleven outbox events were delivered with one attempt each; no pending or failed outbox events remained at the final checkpoint.
- Actual orders used offline checkmo invoices, with no payment capture at a gateway, shipment or order email. These were Magento service-level order placements, not a full browser checkout submission. Purchase and Return totals/balance changes are asynchronous on the remote service.
- A real coupon purchase spent coins but returned a code with both discountPercentage and discountAmount null. The module correctly refused to invent a discount; the confirmed response was retained for reconciliation. After the tenant product was configured and the user authorized one additional purchase, the replacement spent exactly 1050 coins and applied 10% discount. Repeating the same cart/SKU request reused the coupon without another spend. Offline invoicing delivered Purchase and coupon redemption once; an independent remote GET confirmed redeemed=true for the intended customer. Resaving the order produced no duplicate events. The original invalid coupon's refund remains unconfirmed; do not retry its spend or erase its journal.
- Authenticated REST updates succeeded independently for both stores; missing credentials returned 401 and invalid input returned 400. Local requests simulated the inbound webhook with actual tenant balances. This does not prove an externally hosted tenant can reach the local magento.test callback URL.
- The browser run exposed a missing non-cacheable marker on the dedicated account layout. Added cacheable=false there, retained the privacy guard on shared pages, and added a regression test. After cache cleaning, Luma refreshed stale balances without another login and Page Builder received the refreshed customer section. Hyva displayed the same account fields and sidebar correctly. The temporary second-store Hyva theme override was removed afterward.
- Current automated verification: 51 unit tests / 85 assertions, 16 Magento runtime tests / 80 assertions, and 6 JavaScript tests passed (73 tests total). PHP syntax for the manual runner and git diff whitespace checks also passed.
- Still required before sign-off: reconcile the original invalid coupon and earlier uncertain product attempt, verify server-side idempotency across an ambiguous network failure, external inbound webhook reachability, and representative load / merchant checkout integration. These results are not an unconditional production-readiness guarantee.

Detailed local evidence and resumable state are in the gitignored Test/Manual/output directory. It contains test-customer data and must not be published as a product feed or committed with credentials/customer details.

## HTTP 400 Correction, 2.6.1

The supplied redemption contract lists twelve definitive HTTP 400 `message` codes. Only exact matches with HTTP 400 are classified as refusals; HTML, malformed JSON, unknown codes and the same message under HTTP 5xx remain ambiguous. Tests cover the complete allowlist, repeated rejection followed by success, confirmed-response reuse, timeout after a retry, isolation between different SKUs for the same customer, and the storefront's HTTP 400/error-type response. Verification: 73 unit tests / 133 assertions, 21 runtime tests / 124 assertions and 6 JavaScript tests passed (100 tests). Runtime tests use a mocked remote API and rolled-back fixtures; no new coins were spent.

A read-only call to the provided `GET /api/v1/loyalty/shop/:identifier/cart` endpoint succeeded against the test tenant and returned `reservedCoins`, `availableCoins` and an empty `products` list. The supplied product schema contains `sku`, `quantity` and `coinPrice`. Recovery now reads this cart for uncertain physical redemptions and restores exactly one matching reserved unit locally, without sending another mutation. Conflicting open journals, other active loyalty carts and pending/failed physical purchase/removal events prevent automatic recovery. The existing bounded recovery cron also performs these read-only checks; it never retries a rejected or uncertain remote purchase. Missing, malformed or ambiguous products remain protected because read-after-write consistency and request completion are not guaranteed. The provider has not guaranteed idempotent mutations, so merely waiting and resending is not treated as safe. Cart lookup is for physical reservations and does not establish whether a discount coupon was issued.

After adding cart reconciliation and all-code storefront coverage: 97 unit tests / 239 assertions, 26 runtime tests / 168 assertions and 6 JavaScript tests pass (129 tests), rechecked on 2026-09-29 for release 2.6.1. Each of the twelve rejection codes is tested through both the product and coupon controllers, including HTTP 400, a specific error type and Dutch translation coverage. Both storefront aliases remain registered. The real Magento recovery cron is exercised with mocked HTTP transport: a timeout followed by one matching remote item restores the free line without a second POST; an empty remote cart remains uncertain and never inserts an unreserved item. Malformed, duplicate and wrong-quantity responses, conflicting operations/removals, and the separation from coupon recovery are covered. Full DI compilation passed after retrying a generated-directory cleanup failure; caches were cleaned, maintenance disabled and the temporarily paused local webcontainer resumed. Positive cart recovery has not been fault-injected against the live tenant; only the read-only empty-cart schema was observed live. These changes ship in 2.6.1 and do not modify the published 2.6.0 tag.

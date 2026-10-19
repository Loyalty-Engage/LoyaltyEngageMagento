# Live Demo Acceptance, 2026-10-19

Release: 2.6.4. Run: LE-E2E-1019. Environment: local magento.test, Magento Open Source 2.4.8 / PHP 8.3.16, connected to a real test Loyalty Engage tenant. The maintainer authorized test orders, returns and both redemption types and accepted the remaining review findings as non-blocking.

## Verified Flows

| Flow | Evidence |
| --- | --- |
| Browser purchase, store 1 | Order LE-E2E-1019-PURCHASE (96), offline invoice 31. Pending produced no Purchase; processing produced one accepted event. |
| Two partial returns | Creditmemos 6 and 7 each exported one unit with the original order reference. Resaving the order/creditmemo produced no duplicates. |
| Physical redemption | Order LE-E2E-1019-PHYSICAL (97), invoice 32. Paid and free copies of the same SKU remained separate. One reservation was finalized and repeating add did not reserve again. |
| Quantity-only minimum | Native Luma cart update reduced paid quantity from 2 to 1 with a store minimum of 50. The free item disappeared, the paid item remained and one removal event released the reserved coins. Raising quantity allowed a new reservation. |
| Coupon and native tier exclusion, store 2 | Order LE-E2E-1019-COUPON (98), invoice 33. A real Magento tier reduced 24-MB01 from 34 to 30 at quantity 2. A 10% loyalty coupon discounted both this line and ordinary 24-MB02. Purchase exported only 24-MB02, proving coupon-only discounts remain eligible. Both line decisions were captured in product_options. |
| Coupon confirmation | A repeated coupon request reused the same code without spending again. Independent remote GET confirmed 10% and redeemed=true for the intended test contact. |
| Filtered partial refund | Creditmemo 8 contained one unit of each line. Return exported only 24-MB02 and retained the original order reference. |
| Remove-all and expiry | Both removed only reward lines. Each delivered removal returned the reservation; paid lines survived. Only this run's reward timestamp was aged for the expiry test. |
| Review | Review 350 exported once after approval. Pending and repeated saves added no event. |
| Store-scoped updates | Real local REST calls returned 401 without credentials and 400 for invalid input. Updating store 2 changed its fields while store 1 retained its values. |
| Account and Page Builder presentation | Luma and Hyva displayed the stored fields. Hyva showed newly synced store-2 data without another login. The Luma homepage Page Builder block displayed the final synced values. |
| Lost-response recovery | After a completed removal cycle, one real add for 24-MB02 succeeded remotely and the manual runner deliberately discarded the response locally. A separate recovery request restored the free item through the real cart GET with zero additional POST calls. The old applied cycle became released; the current journal became applied with reconciled_from=loyalty_cart. The reservation was then removed and released. |

All fifteen outbox events (339 through 353) ended sent with attempts=1 and no error. Final remote cart inspection showed no remaining test reservation. Detailed contact-level payloads and test state are retained in the ignored Test/Manual/output directory.

## Cleanup and Limits

- Temporary minimum, tax-basis, tier-filter and order-email settings were restored from exact scoped backups. The native product tier fixture and temporary Hyva theme override were restored. Remaining fixture carts were closed with their paid items and journals preserved.
- All three invoices and refunds used offline checkmo processing. No gateway capture or shipment was made. Order email was disabled for browser placement and restored afterward.
- The demo initially lacked a running RabbitMQ service, causing the first browser submission to fail before an order existed. Starting its existing container restored checkout. No loyalty code change or deployment-configuration write was needed.
- Hyva cart/account were tested live. This demo has no Hyva Checkout or fallback checkout installed; its checkout displayed the theme's missing-checkout notice. Both completed browser orders used Luma. This run does not certify Hyva Checkout integrations.
- The first lost-response attempt for 24-MB01 remained protected because an older manual journal for that SKU still requires review. That historical journal was preserved. The deliberately created test reservation was explicitly removed remotely and only its own uncertain journal was marked released. The clean-SKU test then proved automatic recovery succeeds without bypassing the conflict guard.
- Store 2 used inclusive event pricing, but the final dummy-address order had zero VAT, so its historical inclusive/exclusive unit prices were equal. The automated Magento/runtime tests separately verify nonzero VAT, order currency, creditmemo fallback and store isolation.
- Inbound updates were real local authenticated HTTP requests using current remote balances. Externally hosted webhook reachability, production-scale performance and remote mutation idempotency are not established by this run. Lost-response recovery succeeds without assuming remote POST idempotency.
- The separate REST storefront/API origin classification finding remains documented in RELEASE_REVIEW_2026-10-19.md and was accepted by the maintainer for this release.

Automated verification accompanying this acceptance: 131 unit tests / 380 assertions, 40 runtime tests / 251 assertions, 21 native tier tests / 42 assertions and 6 frontend tests. Total: 198 tests, 673 PHP assertions. DI compilation and local schema upgrade passed; upgrade SQL adds two indexes to loyaltyshop_mutation only.

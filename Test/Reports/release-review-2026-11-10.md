# Release review - 2026-11-10

Decision: do not publish the current candidate as a completed wallet release.

Follow-up 2026-11-11: interactive offline checkout acceptance now passed on both
themes; two checkout issues found during testing were repaired. See
wallet-browser-2026-11-11.md for the final 200-test suite, native regressions,
four browser orders, restored settings, and remaining PSP certification limits.
The original findings below are retained as the dated review record.

## Findings

1. Resolved in the follow-up: wallet checkout now invokes DiscountPurchase through
   Funding when the new coins_required opt-in is enabled. Durable purchase
   intents and funded-balance accounting support partial failure and retry.
   Live tenant acceptance subsequently passed for one EUR 10 purchase and
   three EUR 10 purchases in a EUR 30 payment; all four codes were verified
   redeemed. See wallet-live-2026-11-10.md for scope and the initial HTTP 400.
2. Interactive Luma/Hyva checkout acceptance remains incomplete. Earlier native
   transaction and HTTP tests passed, but neither they nor the unit suite certify
   a merchant PSP. The local database, PHP and search containers have been
   restored and setup:upgrade succeeded. Current browser acceptance remains open.
3. Wallet Tender::settle iterated order items on every saved order, including
   ordinary orders. Added an indexed tender existence check before touching
   items. A regression test asserts getAllItems is never called without a tender.
   Historical wallet orders still receive corrections when wallet is disabled.

## Existing-customer checks

Wallet earning/spending defaults remain disabled. The schema adds module-owned
tables, not columns to core sales tables. ConfigureCronConsumers remains a no-op
and no setup patch writes deployment configuration. REST routes use the private
module ACL; authentication is attached to authorization. Return export retains
order identity, store configuration, purchase eligibility and parent/item guards.

Wallet plugins still perform indexed lookups for existing orders even with the
feature disabled, to support historical refunds. No large-catalog/order-volume
performance benchmark was performed. Existing tests cover event export, API,
cart removal, store scoping, account metadata and frontend personalization.

## Verification

- PHP 8.4.6: 198 tests, 78,243 assertions passed.
- Frontend JavaScript: 25 tests passed.
- Prior review: 33 XML files parsed and schema validated; the current schema
  also passed the local Magento setup:upgrade.
- Current native order/payment/refund tests: 52 checks per store (default and
  hyva). These are server-side integration tests, not interactive theme tests.
- Current native accrual: 22 checks; recovery: 8 checks. Financial fixtures
  were rolled back or removed. No real PSP or external voucher purchase used.
- git diff --check passed.
- Current Magento setup:di:compile succeeded, including installed Hyva modules.
- Concurrent reservations on independent PHP/DB connections passed: one hold
  succeeded and the other was rejected, without overspending either ordinary
  or coin-backed credit. Fixtures removed.
- Coin-backed native checkout: 39 checks per store, using fake API transport,
  real orders and non-dispatchable outbox fixtures. Covers refusal, payment
  failure, retry, cancellation, returns, timeout, persisted-response recovery,
  store isolation, invalid config and disabling coupling with existing backing.
- Cart recovery explicitly excludes wallet voucher operations. Funding applies
  the receipt atomically with funded credit and its redeem outbox event. A
  dedicated unit regression verifies the recovery query isolation.
- HTTP wallet forms passed on both stores (login, apply/remove, CSRF, account
  privacy, checkout and logout), both uncoupled and coin-coupled. Whole-euro
  input and server-side flooring of EUR 10.99 to EUR 10 were verified. Test
  settings and fictional balances were restored. These are not interactive
  browser/PSP tests.

## Updated return policy

New wallet orders forfeit the spent wallet portion on a return. Credit memos
still record that portion to prevent a cash refund or duplicate allocation,
but no payment_refund ledger credit is created. Existing tenders retain their
historical restoration policy. The new policy flag is in the module-owned
tender table only. Full-return and repeated-save checks passed in both stores;
a focused unit test asserts no ledger booking for the forfeiture policy.

The failed-payment voucher recovery flow is now implemented and tested with
fake transport; successful single/multiple purchases were also tested live.
An unknown remote outcome still requires reconciliation because
the shared API contract has no guaranteed idempotency or purchase lookup.
No version bump, push or release was performed.
The uncommitted candidate contains substantial wallet work despite this small
review fix; disabled defaults alone do not certify the full change for production.

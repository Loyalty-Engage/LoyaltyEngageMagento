# Wallet earning and payment acceptance - 2026-11-05

Local development candidate for 2.6.6, not a published release.

## Environment

Magento Open Source 2.4.8, PHP 8.3.36, MariaDB 11.4, Luma,
Hyva Theme 1.5.3, Hyva Checkout 1.3.15. EUR, customer/store-scoped balances.
Wallet earning and spending are both disabled by default.

## Automated and native acceptance

- PHPUnit: 189 tests, 78,146 assertions, passed.
- Frontend Node suite: 25 tests, passed; wallet AMD syntax check passed.
- PHP/template syntax: 215 files passed.
- Module configuration/admin/layout XSD validation passed. Duplicate
  QuoteManagement DI declarations were consolidated during final validation.
- Magento setup:di:compile passed; setup:db:status reports all modules current.
- wallet-local.php: 22 accrual/refund/cancellation checks passed.
- wallet-payment-local.php: 48 native payment checks passed, including partial
  and full wallet payment, zero-cash invoices/refunds, partial invoices,
  partial/full creditmemos, native invoice/refund service APIs, cancellation,
  repeated saves, stale document rejection, failed placement and deleted owners.
- wallet-recovery-local.php: 8 checks passed, including inconsistent-order
  quarantine, transient-failure backoff and continued processing of other holds.
- wallet-concurrency-local.php: two independent PHP/database workers competing
  for EUR 60 each from EUR 100 produced one reservation and one rejection.
  EUR 40 remained available; cross-process advisory locking was verified.
- Native PDF binary generation and wallet totals model passed. PDF visual
  appearance has not been reviewed.

Database integration tests use explicit local-test opt-in. Financial order
fixtures are rolled back; concurrency/recovery fixtures are explicitly removed.
No live PSP transactions or external Loyalty Engage redemptions were made.

## Storefront acceptance

HTTP/native-form checks on both Luma and Hyva cover partial/full application,
removal, payable-total changes, checkout rendering, CSRF/negative-input rejection,
private account balance, route alias and logout isolation. No storefront order
is placed with the temporary EUR 100 fictional-customer fixture. Original
configuration and balances must be restored after each run.

These checks do not replace interactive browser testing of custom checkout
JavaScript, nor certify Hyva Checkout 3.x.

## Correctness safeguards

Wallet payment remains a tender rather than a tax or product-price discount.
Accrual uses the original cash fraction of eligible merchandise with cumulative
line allocation. Invoice/refund allocations are cumulative and capped in cents.
Uninvoiced canceled tender is returned once; invoiced tender uses creditmemos.
Committed orders are never blindly released after a lost browser response.
Deleted-owner refunds are audited as unclaimed credit, not recreated balances.

Only module-owned tables are extended. No new core sales-table columns,
order-grid joins, historical migration scans, env.php writes or global
consumer/locking configuration changes are introduced by wallet payment.

## Required merchant staging acceptance

Actual PSP authorize/capture/refund/cancel/timeout behavior has not been tested.
Only enable a PSP after staging acceptance; defaults allow free/checkmo/banktransfer
without activating them. Complex product/tax configurations and other custom
totals/tender integrations need their own tests. Earlier Magento versions have
not received a full runtime acceptance run.

No multishipping, admin order-edit wallet selection, headless selection API or
voucher-to-wallet top-ups are provided. Direct percentage earning and spending
are available independently of voucher top-ups. See WALLET_2.6.6.md for setup
and scope. This report does not assert universal production certification.

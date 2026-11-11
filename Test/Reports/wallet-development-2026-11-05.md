# Wallet accrual development checks - 2026-11-05

Historical accrual-only checkpoint. Superseded for payment scope by
[wallet-payment-2026-11-05.md](wallet-payment-2026-11-05.md).

## Scope and status

Development preview for target 2.6.6, NOT production-ready store credit.
Wallet accrual remains disabled in the demo and by default in the module.
No commit, push, tag or release was made for this development work.

Implemented: configurable tier percentages/name, EUR accounting policy, new-order
snapshots, completion-based accrual, indexed customer/store balances, idempotent
ledger, return/cancellation corrections, private account route and overview.
Checkout spending, invoice/cash-refund tender allocation and voucher top-ups
are NOT implemented. No existing checkout totals are changed.

## Environment

- Local isolated Magento Open Source 2.4.8, PHP 8.3.36, MariaDB 11.4 demo.
- Luma, Hyva Theme 1.5.3 and Hyva Checkout 1.3.15.
- Unit tests use host PHP 8.4.6, PHPUnit 10.5.48.

## Passed

- 174 PHP unit tests and 25 frontend JavaScript tests (199 total).
- Money parsing, strict validation, basis-point rates, store isolation in config,
  no reward for unknown tiers, no earning on wallet tender in the policy engine,
  exact cumulative refund rounding and bounded overflow-safe integer ratios.
- Guards against captures for disabled accrual, guests, existing orders or foreign
  currencies; ledger rejects writes outside a transaction.
- Native setup:upgrade, full dependency-injection compilation (51 seconds),
  setup:db:status: all modules up to date.
- 22 real database checks in Test/Integration/wallet-local.php. Uses native
  order/creditmemo resources and the actual order-relation plugin/refund observer,
  with explicit fixture capture. No gateway or remote redemption call.
- Pending vs complete credit; order and creditmemo resaves; partial/full return;
  store separation; original-tier snapshot; full/partial cancellation; refund
  before vesting; no retroactive rewards; gold 2%; inclusive tax; refund after
  changing the tax setting. Test fixture writes rolled back, verified by ledger
  count and both store balances after rollback.
- Native order storage ordering verified: item IDs exist at the relation plugin,
  before the encompassing transaction commits. A save_after observer would run
  too early for the first-order snapshot, so no such observer was installed.
- Repeated Luma/Hyva HTTP regression: login/logout, public CMS identity isolation,
  cart quantity increase/decrease, checkout targets. Both wallet frontName aliases
  return HTTP 200 while authenticated, no-store protection; logged-out wallet
  access resolves to the native login form.
- PHP syntax checks of wallet services/observers/controller/plugins/unit tests;
  git diff --check.

## Remaining release blockers

- Atomic checkout reservation/debit and recovery, partial/full-wallet payments,
  native Luma/Hyva Checkout controls and server-side owned-cart validation.
- Mixed-tender invoices/refunds and payment-gateway integration acceptance.
- Confirmed, uniquely identified voucher redemption -> EUR top-up mapping.
- Real concurrent DB fault-injection, gateway failure/lost-response scenarios,
  large-catalog/order-volume performance acceptance.
- Full native quote submission with enabled config, positive-balance account
  display in a browser, complex configurable/bundle/tax fixture acceptance.

The database test keeps an outer rollback transaction. It demonstrates native
resource lifecycle, idempotent state and rollback isolation, not independent
concurrent connection behaviour or production gateway correctness. It deliberately
does not enable this preview in production or award credit to a real customer.

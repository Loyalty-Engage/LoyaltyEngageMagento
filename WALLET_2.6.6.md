# Personal EUR wallet - 2.6.6

Earning, payment and Loyalty Engage coin coupling are disabled
by default. Native offline / zero-cash acceptance does NOT certify an arbitrary
PSP, third-party totals module or customized checkout.

## Configuration

Stores > Configuration > Loyalty Engage > Loyalty > Wallet.

- Enable Wallet: master switch for new accrual snapshots and wallet availability.
- Wallet Name: merchant/store-specific, no customer branding hard-coded in code.
- Earn Percentage per Tier: exact store-scoped le_current_tier value and a
  percentage, e.g. base = 1 and gold = 2. Unknown tiers earn zero. No default
  percentages are supplied. Later tier/rate changes never reprice an order.
- Include Merchandise Tax in Earn Basis: default No. Merchandise is after
  discounts and excludes shipping. Captured tier-price exclusions are respected.
- Enable Wallet Payment: separate opt-in, default No.
- Debit Loyalty Engage Coins for Wallet Payments: separate opt-in, default No.
  Configure all four dedicated wallet discount product SKUs before enabling.
- Wallet-Compatible Payment Methods: defaults free, checkmo and banktransfer.
  This does not activate these Magento methods. Enable Zero Subtotal Checkout
  and allow free if the wallet may cover the whole order.

Balances belong to customer ID AND store ID, only in EUR. Both base and quote/
order currency must be EUR. No silent conversion, email transfer, general coupon,
global balance or historical order backfill is provided.

## Customer flow

1. A logged-in customer places an eligible new order. Magento captures tier,
   rate, net merchandise and tax basis at native quote submission.
2. At status complete, eligible merchandise earns euro credit once. The
   original cash-tender fraction determines the eligible merchandise portion,
   consistently with invoice/refund allocation. Wallet-paid value earns nothing.
   Rounding is at order level, with cumulative line allocation.
3. The named account tab, /loyalty/wallet/index and existing Loyalty account
   page show the private available balance and recent bookings.
4. Cart/checkout provides an amount field. Enter 0 to remove the selection.
   Native form POST + form key works on Luma and Hyva; page reload refreshes
   totals, payment methods and checkout state.
5. Applied credit is capped by balance and payable total. Products, tax and
   sales-rule discounts stay native: credit is a tender, not a discount.
6. Placement reserves funds atomically, then converts the hold to one payment
   in the native order transaction. A normal allowed method pays the remainder;
   fully covered orders use Magento's free method.

Merchants independently decide whether Loyalty Engage points AND euro credit
should both be earned. Existing Purchase/Return export is not silently disabled.

## Invoices, cancellation and returns

Invoice allocations use cumulative original-order proportions and remaining-cent
caps across partial invoices. For new orders the wallet share is forfeited on
return; only the cash grand total reaches the normal payment refund flow.
Previously stored tenders preserve their original wallet-restoration policy.
The policy is stored on the module-owned tender row. Creditmemo totals identify
forfeited wallet credit explicitly. Zero-cash wallet refunds never call a PSP. Native refund eligibility
recognizes wallet funds without enabling zero-total refunds platform-wide.

Cancellation restores only uninvoiced wallet tender, once. Invoiced amounts use
creditmemos, not a second cancellation refund. Merely setting complete does not
release an uninvoiced payment. Pending invoice persistence is respected when
native API order/invoice save ordering differs.

Returns reverse the originally earned reward, using cumulative original values
and captured percentages. Pre-vesting returns, partial cancellations, resaves
and rounding are handled. If earned credit was spent, retain a negative
accounting balance; available spending is zero and future earnings offset debt.

Historical wallet lines appear in native frontend/admin/email totals and PDF
invoices/creditmemos. Account history distinguishes earning, payment, payment
refund and unused-tender release.

## Integrity and deployment

- Integer euro cents / basis points, with native four-decimal conversion only at
  monetary boundaries. Non-wallet totals are unchanged.
- Module-owned balance, ledger, order, quote, tender and document tables only;
  no new sales_order, sales_order_item, invoice or creditmemo columns/indexes.
- Append-only operation-key ledger and indexed balances, no whole-ledger SUM,
  order-grid joins, sales-table migration scans or historical backfill.
- Short reservation transaction before gateway placement. Wallet-only database
  advisory locks work across web nodes, independently of global lock-provider
  settings. No env.php or consumer-runner configuration writes.
- Fresh native monetary state and quantities after the order lock, by primary
  order ID and indexed order-item lookup, only during document operations.
- Totals collection never debits/reserves. Quote presentation metadata is saved
  at native quote save and is never authorization for final payment.
- Session-owned active carts, store/currency validation and CSRF checks; no
  anonymous wallet adjustment/balance-transfer API.
- Switching stores never transfers money. Deactivated/unsupported selections
  require total/payment review, not a silent increase in the cash charge.

The hold cron runs every 5 minutes: up to 50 holds older than 15 minutes and a
10-second budget. It releases only after obtaining the quote lock and verifying
that no order exists for the reserved increment/store. Live placement stays
protected. Lost browser responses for committed orders never repeat a debit.
Inconsistent existing-order bindings are logged, not blindly released/replayed.
They are quarantined for review; transient failures get retry backoff so old
records cannot starve newer recovery. Other unreserved funds are never
profile-wide blocked. Reserved amounts are visible in the account overview.

Customer/store deletion cascades quote holds with the balance. Old-order refunds
for deleted owners create unclaimed_* ledger bookings and warnings for merchant
reconciliation, not replacement accounts or email-based transfers. Cash refunds
can still proceed. Merchants need a policy for deleted-account/unclaimed credit.

## Scope and acceptance

Local acceptance: Magento Open Source 2.4.8 / PHP 8.3.36 / MariaDB 11.4, Luma,
Hyva Theme 1.5.3 and Hyva Checkout 1.3.15. Storefront acceptance is HTTP/native
forms, not certification of every browser script or Hyva Checkout 3.x.

Single-address logged-in storefront carts only. No multishipping, admin order-edit
wallet selection or headless selection API. Native invoice/refund business APIs
are integrated. Common native store-credit/gift-card/reward-tender combinations
are refused rather than guessing a three-way refund. Other custom totals/tender
modules need explicit staging integration.

Before production, test actual allowed PSPs: authorize, capture, partial invoice/
refund, cancellation, failed/timeout responses, plus customized storefronts and
complex product/tax cases. No live payments, PSP credentials or external Loyalty
Engage redemption were used in local wallet acceptance.

## Loyalty Engage redemption SKUs

Configure EUR 1, EUR 5, EUR 10 and EUR 50 Discount Product SKU in the Wallet
group. Use distinct Loyalty Engage discount product SKUs, not coupon codes or
Magento catalog SKUs. The product's points or coin cost is configured in Loyalty
Engage; a denomination is its EUR face value, not its points cost.

Enable Debit Loyalty Engage Coins for Wallet Payments to activate the coupling.
The coupled mode rounds DOWN to whole euros after capping by requested credit,
available balance and payable amount. EUR 12.50 therefore uses EUR 12, buying
one EUR 10 and two EUR 1 products when no previously purchased backing exists.
At most 20 new vouchers may be bought in one placement attempt.

Each purchase first stores an immutable intent (store, customer, quote,
hashed contact, SKU and euro value). The API response is journaled outside the
order transaction. Confirmed purchases mark existing euros as coin-backed;
they do NOT mint extra wallet credit. Wallet reservations protect both the
ordinary balance and the purchased portion. A customer/store database advisory
lock serializes checkout funding, with only short accounting transactions.

On an explicit refusal, the checkout releases its local holds. Confirmed prior
purchases remain available; the next attempt buys only the missing value.
Failed payment or an uninvoiced cancellation likewise preserves purchased
backing, including use in another cart. New-order returns forfeit spent wallet
and backing; no coins-return endpoint is assumed. Disabling coupling does not
erase or double-spend existing purchased backing.

Timeouts and malformed/unconfirmed responses are NOT proof of rejection. Their
durable reference requires reconciliation; do not delete it or blindly retry.
Ordinary checkout without wallet remains available, as does spending sufficient
already-confirmed backing. A response already in the journal can be applied
after a local crash without another purchase. A still-unknown remote outcome
cannot be automatically resolved by this discount API contract.

Confirmed purchases enqueue distinct durable redeem-discount events, one per
voucher, even if the order subsequently fails (the purchased euro value remains
in the wallet). Run the existing outbox/consumer infrastructure. Do not use
these dedicated wallet products as ordinary exported/promoted coupons. Generated
LE-WALLET codes are blocked as Magento sales-rule coupons, so they cannot also
discount the same cart while redemption delivery is pending.

The module-owned funding table retains the audit trail. No core sales-table
columns, deployment config writes, grid joins or full order scans are added.
The initial coin cost is controlled by Loyalty Engage; Magento maps only the
euro denomination. Keep tenant/credential changes and unresolved purchases under
merchant change control; never point a store at a different tenant mid-recovery.

## Optional voucher top-ups

Not implemented in this candidate. Direct percentage earning and spending do not
require top-ups. A future merchant mapping must explicitly specify reward SKU ->
EUR amount. Products costing 1, 5, 10, 50 or 100 coins imply no euro conversion.
Credit only after confirmed, uniquely identified redemption; a cart reservation
is not proof of purchase. Never blindly retry an ambiguous external debit.

See [wallet-acceptance-2026-11-09.md](Test/Reports/wallet-acceptance-2026-11-09.md)
for the latest regression results and live activation gates. The earlier
payment and accrual reports are historical checkpoints.

### Repeatable local storefront test

Run database fixture suites sequentially: accrual/payment tests share the same
fictional customer and deliberately hold rollback transactions. Do not compile
Magento while tests or storefront requests are generating code.

The native payment suite accepts LOYALTY_WALLET_TEST_STORE=default or hyva.
Both use LOYALTY_WALLET_LOCAL_TEST=1 and roll back financial fixtures.

wallet-coins-local.php uses independent temporary customers and real native
orders, with a fake API transport and non-dispatchable outbox fixtures. It tests
partial rejection, payment failure, reuse, cancellation, return forfeiture,
unknown outcomes, journal recovery and store separation. Run it separately for
default and hyva. This is NOT a live Loyalty Engage or interactive browser test.

For storefront form acceptance, run wallet-storefront-fixture.php enable, then:

```sh
LOYALTY_WALLET_LOCAL_TEST=1 \
LOCAL_WALLET_TEST_PASSWORD='<fictional local account password>' \
LOCAL_WALLET_TEST_CA='/path/to/local/magento.test.pem' \
node Test/Integration/wallet-storefront-local.cjs
```

Always run wallet-storefront-fixture.php restore afterwards, including after a
failed test. This script verifies TLS using the supplied local certificate,
accepts only https://magento.test and never places an order. It requires the
existing demo product and fictional personalization-demo@example.com profile.
No real customer's credentials, balance or orders should be used for fixtures.

For the coin-coupled form variant, enable the fixture with the extra `coins`
argument and set LOCAL_WALLET_TEST_COINS=1 for the HTTP test. It verifies whole
euro input and server-side rounding. It still never places an order or buys
discount products. Always restore the fixture afterwards.

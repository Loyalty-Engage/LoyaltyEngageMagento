# Wallet release acceptance - 2026-11-09

Candidate 2.6.6, uncommitted and unreleased. This is a local regression run,
not a blanket production approval.

## Passed

- PHPUnit: 189 tests, 78,146 assertions.
- Frontend Node suite: 25 tests.
- Native payment suite: 48 checks on default/Luma and 48 on the hyva store.
  Explicit store selection is now supported with LOYALTY_WALLET_TEST_STORE.
- Accrual suite: 22 checks. Recovery suite: 8 checks.
- Two independent PHP/database workers: one EUR 60 hold accepted, the competing
  EUR 60 hold rejected against EUR 100; EUR 40 available, no debit. Cross-process
  advisory locks verified. Temporary concurrency fixtures removed.
- Native payment/refund coverage includes mixed/full-wallet orders, invoices,
  partial/full creditmemos, cancellation, repeated saves, lost-response retry,
  native invoice/refund service APIs and deleted-customer handling. Financial
  test transactions were rolled back.
- HTTP/native-form storefront suite passed on default/Luma and Hyva: partial/full
  application, removal, payable totals, checkout rendering, CSRF/negative input,
  private account balance, legacy route alias and logout isolation.
- Magento setup:di:compile completed successfully; database schema current.
- XML schema validation, PHP/template syntax (215 files), storefront test Node
  syntax and git diff --check passed.

The new Test/Integration/wallet-storefront-local.cjs is self-contained, checks
TLS against an explicit local certificate, restricts requests to magento.test
and never places an order. See WALLET_2.6.6.md for fixture setup/restoration.
The temporary storefront balance and store-scoped configuration were restored
successfully after both storefront runs.

## Test environment and execution notes

Magento 2.4.8 / PHP 8.3.36 / MariaDB 11.4; Hyva Theme 1.5.3 and
Hyva Checkout 1.3.15. Existing demo containers/database/vendor volumes survived,
but temporary PHP/Nginx/certificate files had been removed. Local configuration
and a self-signed certificate were recreated; no trust-store change was made.

An initial concurrent run of accrual/payment fixtures deadlocked while setting
up their shared fictional customer. Sequential runs passed. Initial compilation
attempts overlapped code-generating test processes; compilation passed when run
alone. Run shared financial fixture suites and compilation sequentially. These
failed development attempts remain in logs and are not hidden as clean runs.

## Not yet verified / live activation gates

- Interactive browser checks were blocked by the new self-signed certificate;
  user acceptance was requested. HTTP form success is not interactive browser
  or client-side payment-method switching certification.
- No actual PSP authorize/capture/refund/timeout transactions were performed.
  Test the merchant's configured PSP on staging before adding it to the wallet
  payment-method allowlist and enabling spending in production.
- No Hyva Checkout 3.x, customized checkout, exhaustive complex-product/tax or
  other third-party tender-module certification is implied.
- Voucher-to-wallet top-ups remain outside this candidate.

Wallet remains disabled by default. No release tag, push or deployment was made.

### Interactive follow-up

After user certificate acceptance, native Chrome loaded the Luma login and
authenticated the fictional customer (My Account tab). The in-app browser still
rejected the self-signed certificate. Native Chrome testing was interrupted by
simultaneous user interaction, so wallet/cart/checkout browser acceptance is
not yet complete. The temporary wallet fixture was restored after stopping.

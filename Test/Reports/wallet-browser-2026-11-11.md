# Wallet acceptance - 2026-11-11

Scope: local magento.test, installed Luma and Hyva Theme / Hyva Checkout.
Offline Check / Money Order and Zero Subtotal only; no PSP credentials or live
payment. Dedicated fictional customer 34, EUR 100 initially in each store.
Coin coupling was disabled for these browser orders. Real single/multiple
voucher acceptance is documented separately in wallet-live-2026-11-10.md.

## Interactive results

All four orders reached the visible success page and were verified against
native orders and wallet balances:

| Theme | Order | Wallet | Remaining payable |
| --- | --- | --- | --- |
| Luma | 000000165 | EUR 10 | EUR 20, checkmo |
| Luma | 000000180 | EUR 30 | EUR 0, free |
| Hyva Checkout | 2000000070 | EUR 10 | EUR 20, checkmo |
| Hyva Checkout | 2000000071 | EUR 30 | EUR 0, free |

Both stores ended at EUR 60, with zero reserved and zero reserved-funded credit.
The account wallet showed the two correct transactions in each store. Logout
was confirmed visibly, and accessing the Hyva wallet afterwards redirected to
login without showing the balance. No real customer coins were spent today.

## Issues found and repaired

1. Loaded quotes can collect shipping before empty billing. Empty billing was
   resetting the runtime wallet amount, rejecting placement. Reset now occurs
   once at quote collection start; empty assignments do not erase the tender.
   A regression covers this order and the next empty-cart collection. The four
   browser orders above were placed after this repair.
2. Selecting shipping after applying full wallet credit could leave the Luma
   wallet segment at its old amount, despite correct payable/order amounts.
   The shipping response now refreshes wallet quotes and explicitly persists
   presentation metadata. A zero grand total can otherwise cause native saving
   to skip save_after. Ordinary quotes do not incur the added recollection/save.
   Reproduced through the native shipping-information service; the regression
   failed before the repair and passed afterwards on both stores. This final
   response repair was verified through integration/unit tests, not a second
   complete browser-order cycle.

## Final verification

- PHPUnit: 200 tests, 78,258 assertions, passed.
- Frontend JavaScript: 25 tests, passed.
- Native checkout/invoice/refund/cancellation: 54 checks per store, passed.
- Native coin funding and failure recovery: 39 checks per store, fake transport.
- Accrual: 22 checks; abandoned hold recovery: 8 checks, passed.
- Independent PHP/DB concurrency: one reservation accepted, one rejected; passed.
- Magento dependency injection compilation including Hyva: passed.
- Test configuration restored; fictional browser customer/orders retained for audit.
- Financial integration fixtures rolled back or removed.

No real PSP callback, provider decline/cancel, or production-volume performance
certification is implied. No push, version bump or release was performed.

## Complete browser rerun after the final fix

Repeated both themes after the shipping-response repair, without further
production-code changes. Resumed the same fictional account at EUR 60 per
store; did not reseed money or purchase external vouchers.

| Theme | New order | Wallet | Remaining payable | Native method |
| --- | --- | --- | --- | --- |
| Hyva Checkout | 2000000091 | EUR 30 | EUR 0 | free |
| Hyva Checkout | 2000000092 | EUR 10 | EUR 20 | checkmo |
| Luma | 000000214 | EUR 30 | EUR 0 | free |
| Luma | 000000215 | EUR 10 | EUR 20 | checkmo |

- Product addition, cart, shipping, payment and success pages exercised visibly.
- Hyva: requested EUR 30 before shipping; wallet segment changed from EUR 25
  to EUR 30 when EUR 5 shipping was selected, with payable remaining zero.
- Luma: full wallet and shipping-information transition displayed EUR 30
  correctly. Repeated the direct-checkout scenario from the original failure;
  payment step again showed EUR 30, not EUR 25. Then changed selection to EUR 10:
  Check / Money Order became available and payable changed to EUR 20.
- All four success pages were observed. Native order/payment records independently
  confirmed totals and methods. Balance and account history ended at EUR 20 per
  store; reserved_cents and reserved_funded were zero in both stores.
- Login and logout exercised on both themes. Logged-out wallet URLs redirected
  to login on both themes. Luma balance remained correct after a fresh login.
- Test settings restored again. Test orders remain for audit. Browser left logged out.

Minor cosmetic observation: Luma can display the earlier wallet-updated success
message on the order success page. No amount, placement or balance error occurred.
This rerun closes the complete-browser-cycle gap described above. PSP callbacks
and live coin purchases were not repeated in this offline browser acceptance.

## Cosmetic message follow-up

Removed the Apply controller's session success message. Updated wallet input
and totals already confirm successful application; Luma could defer rendering
the session message until the order success page. No message collection is
cleared, so unrelated notifications and all existing wallet errors remain intact.
Controller regressions verify successful application/save/unlock without queuing
a success message, and invalid input still queues an error. Final PHP suite:
202 tests, 78,270 assertions passed. No additional browser order was placed after
this message-only change.

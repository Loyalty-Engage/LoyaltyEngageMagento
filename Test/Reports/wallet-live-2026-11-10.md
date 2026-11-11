# Wallet live tenant acceptance - 2026-11-10

Explicitly approved test profile; local magento.test orders only. No real PSP,
payment capture, shipment or order email. External coin purchases are real.
Persistent restricted audit file: Magento var/WALLET-LIVE-20261110.json.

## Results

| Store | Order | Wallet EUR | Purchases | Coins spent | Cash remainder (not paid) |
| --- | --- | --- | --- | --- | --- |
| default (Luma) | LE-WALLET-20261110-10 | 10 | 1 | 10 | EUR 50 |
| hyva | LE-WALLET-20261110-30 | 30 | 3 | 30 | EUR 30 |

Each purchase used VOUCHER-EUR-10-00. Available coins: 3521 -> 3511 -> 3481.
Current points remained 6381. Remote reservedCoins and both local wallet holds
were zero after placement. EUR 10/30 were seeded locally for this isolated test;
both local seeded balances were consumed, not earned through Purchase events.

All four independent redeem-discount outbox events were sent successfully on
their first delivery attempt. Read-only GET of each generated discount code
confirmed redeemed=true, discountAmount=10 and discountCurrency=EUR.

The first EUR 10 attempt returned HTTP 400, with unchanged remote coins and no
order. The original API reason was not retained by the client. After the user's
explicit retry instruction, another unchanged-balance check and repeated GET
404 for the requested code, one operator-approved retry succeeded. The original
uncertain journal is retained; its funding intent is marked operator_retry.
This is NOT an automatic recovery rule or proof that unknown responses may be
blindly retried. The live runner now captures the error message for diagnostics.

## Scope

Native Magento quote/order placement and real API/outbox calls were exercised,
not interactive browser payment placement. Only EUR 10 products were authorized.
Other denomination mappings were process-only unused placeholders, protected by
a transport guard allowing only the exact authorized SKU and maximum 1/3 calls.
No placeholder SKU or wallet-enable setting was persisted to merchant config.

This confirms multiple voucher purchases and redemption delivery in the linked
tenant. It does not certify a merchant PSP or all four configured denominations.
Keep orders, funding records and mutation receipts for reconciliation; deleting
test records does not refund external coins.

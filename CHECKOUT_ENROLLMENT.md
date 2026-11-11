# Checkout enrollment (2.6.7)

Configure the enrollment group under Stores > Configuration > Loyalty Engage.
The mode and checkbox label support default, website and store-view scope.

- Disabled (default): no checkbox and no enrollment-based wallet restriction.
- Informational: display and save the checkbox choice; everyone participates, no opt-in event is generated, and the wallet is not gated by membership.
- Real opt-in: save an explicit choice for the active cart and queue an opt-in when an order is first saved. Existing members do not see the checkbox.

The checkbox uses a normal POST and checkout reload to persist a change. The Save
loyalty preference button provides a fallback without JavaScript. Checking the
box is not itself a completed enrollment: an order must be placed and the queued
event must be delivered. Guest orders use the actual order email.

## Membership and wallet

At least one populated supported Loyalty Engage customer field in the current
store is treated as membership. Numeric zero is populated; null and blank values
are not. A confirmed opt-in outbox event also establishes membership until the
next metadata sync. Keep these outbox records if introducing custom retention.

In real opt-in mode the account balance, wallet navigation and checkout wallet
selection remain hidden/unavailable before membership. Wallet accrual is not
blocked. Loyalty Engage owns the policy for accepting other events before
enrollment; Magento does not suppress ordinary Purchase generation for nonmembers.

The wire payload sent to /api/v1/events is:

```json
[{"event":"LoyaltyEngageOptIn","identifier":"customer@example.com"}]
```

Delivery uses the module event outbox and existing cron job, not a new broker
consumer. Purchase waits if an opt-in for that store/identifier is queued but not
yet sent. Monitor outbox failures: a failed opt-in may defer the related Purchase.
The consent label/time and order reference stay in the local outbox audit payload,
not the API request. Credentials and tenant configuration must be valid per store.

## Upgrade and acceptance

Run the normal Magento deployment steps, including setup:upgrade, dependency
injection compilation and cache/static asset deployment appropriate to the shop.
The new table is loyaltyshop_enrollment_quote; no sales_order/sales_order_item
columns or env.php settings are added or changed. The feature remains off until
explicitly enabled by the merchant.

Local 2.6.7 acceptance covered Luma and Hyva Checkout with a fictional customer,
including both checkbox states, all modes, store isolation and wallet totals.
Order creation and exact event serialization were separately tested in native
rollback-only integration checks using fake transport. Verify real opt-in delivery
and tenant-side acceptance in the merchant's staging tenant before activation.

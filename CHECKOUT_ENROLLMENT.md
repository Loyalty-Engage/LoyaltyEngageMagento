# Loyalty enrollment

Configure Loyalty Enrollment under Stores > Configuration > Loyalty Engage > Loyalty.
The mode and checkbox label support default, website and store-view scope.

- Disabled (default): no checkbox and no enrollment-based wallet restriction.
- Informational: display and save the checkbox choice; everyone participates, no opt-in event is generated, and the wallet is not gated by membership.
- Real opt-in: enable enrollment from checkout, registration, My Account and customer administration. Existing members do not see the checkout checkbox.

The checkout checkbox appears in Luma's after-payment-methods region and in the
Hyva final step's quote actions. It is independent of any newsletter field.
Theme overrides may need to move the block next to a custom newsletter component.
The current value is saved automatically before submitting payment/order data, with
no separate save button or reload. A cart-bound token rejects stale checkout tabs.
An opt-in event is only queued when an opted-in order is saved for the first time.
Guest orders use the actual order email.

Registration and My Account share the configured opt-in text. Registration
queues the event only after a successful account creation; if email confirmation
is required, the customer is directed to confirm and then join from My Account.
The Customers > All Customers > Edit > Loyalty enrollment action requires
customer-management and loyalty-enrollment permissions, an eligible store view,
and explicit confirmation that the customer agreed. It does not affect newsletters.

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
Consent label/time, origin, order reference and (for admin enrollment) admin
user ID stay in the local outbox audit payload, not the API request.
Credentials and tenant configuration must be valid per store.

## Customer CSV export

Go to Customers > Loyalty CSV Export, select one store view and download the CSV.
The export requires both All Customers and Export Loyalty Customers and Wallet
Balances permissions. Adobe Commerce roles restricted by AdminGws are not
supported for this report; only global data-scope roles can export.

The UTF-8, comma-delimited CSV contains one row per Magento customer eligible for
the selected store under Magento's account sharing scope, including customers
not yet enrolled. Guest orders without a Magento customer record are not listed.
Wallet amounts are in EUR, with two decimal places. Available is the balance
minus reserved, with a minimum of zero. The report reads the selected store only,
including when wallet spending is switched off or the store is inactive.

Status values are enrolled, pending, failed and not_enrolled. Source is the
recorded checkout, registration, account or admin origin, or a fallback:
loyalty_sync, magento_legacy, unknown or blank when there is no enrollment.
Historical checkout events with an order reference but no source are reported
as checkout. The export contains customer email addresses; handle it as customer data.

## Upgrade and acceptance

Run the normal Magento deployment steps, including setup:upgrade, dependency
injection compilation and cache/static asset deployment appropriate to the shop.
This update reuses the existing enrollment and outbox tables; no new sales
columns or env.php settings are added or changed. The feature remains off until
explicitly enabled by the merchant.

Local 2.6.7 acceptance covered Luma and Hyva Checkout with a fictional customer,
including both checkbox states, all modes, store isolation and wallet totals.
Order creation and exact event serialization were separately tested in native
rollback-only integration checks using fake transport. Verify real opt-in delivery
and tenant-side acceptance in the merchant's staging tenant before activation.

Extended local browser verification on Magento 2.4.8: Luma stored an unchecked
choice with its order; Hyva Checkout 1.3.15 stored a checked choice and queued
one opt-in with its order reference. Registration, My Account and the customer
admin action each queued an opt-in with the expected source and store. The
registration event was independent of the newsletter checkbox. No real tenant
events were sent. Deploy fresh Luma RequireJS assets and verify payment provider
and newsletter/layout integrations on the merchant's checkout before release.

Release 2.6.9 rerun also verified the opposite choices (checked on Luma,
unchecked on Hyva) against the saved quote selections and outbox. CSV files
downloaded through Customers for both store views matched the stored customer
status, source and wallet values. The rollback-only 505-customer fixture
confirmed batching, reserved amounts, historical fallback and store isolation.

# Frontend personalizations

## Configuration

Open Stores > Configuration > Loyalty Engage > Loyalty > Frontend Personalizations.
Select the intended website/store scope before overriding inherited values.

1. Enable the feature (disabled by default).
2. Enter the frontend tenant UUID or official loader snippet. Only the validated UUID is stored; pasted code is never executed. The API Client ID is a different setting.
3. Enter the Loyalty Engage organization currency, for example EUR.
4. Add a rule with a unique lowercase key and the personalization Public ID.
5. Choose Automatic placement and the page scope, or Manual placement for a widget.
6. Save configuration and clean the Magento configuration, layout and full-page caches.

Add the storefront domain to Loyalty Engage > Developer > Frontend settings > Allowed domains.
Enable browser personalization rendering there as well. Configuration propagation can take time.
Keep debug logging disabled on production unless troubleshooting.

## Placement

Automatic rules render before page content on product, cart, checkout or selected paths.
Selected paths are comma-separated absolute storefront paths (for example /membership,/about-us).
Include store-code prefixes where applicable. The homepage path is /.

Manual rules use the Loyalty Engage Personalization widget in a CMS/Page Builder Text element,
or the directive shown beside the saved rule:

```text
{{widget type="LoyaltyEngage\LoyaltyShop\Block\Widget\Personalization" key="membership"}}
```

Each mounted target receives a unique ID. The official module loader is added once when an
eligible visible target exists. Missing/disabled targets do not load the external SDK.

## Customer Context And Values

Personalized rules obtain the email hash from the current authenticated Magento session via
a private, no-store, same-origin endpoint. Public page HTML and configuration contain no customer
identifier. Guest triggers omit the identifier. Browser-supplied identifiers are ignored.
The endpoint reads an active quote through its repository and does not collect or save totals.

Send value uses product unit price on product pages and saved cart product subtotal on cart/checkout.
Values follow the module's Purchase/Return tax setting, before coupon discounts and excluding shipping.
They are not a calculation of final reward eligibility. Amounts are converted using Magento currency
rates and omitted if the currency/rate is unavailable. Configurable prices are omitted until a variant
is selected. Configure replacement rather than append-only content in Loyalty Engage when values update.

Native Luma and Hyva events are supported, including Hyva Checkout 1.x Magewire message processing.
Custom/headless checkout integrations must explicitly
bridge their route, price and totals updates through the frontend integration and be acceptance-tested.
No browser event may set the customer identifier.

The module whitelists https://le-cloud.app for scripts and connections. Personalization content may
require additional merchant-reviewed CSP image/style/frame hosts; do not disable CSP globally.

## Release Acceptance

Verified on the rebuilt local Magento 2.4.8 / PHP 8.3.36 demo with Luma, Hyva Theme 1.5.3 and
Hyva Checkout 1.3.15: installation, successful dependency-injection compilation and up-to-date schema,
module JS delivery, automatic targets and visible real personalization rendering.
Unit coverage also checks store/owner isolation, guest/logout behavior, currencies, variant updates,
deduplication, remounts, network failures and invalid configuration.

Live HTTP acceptance checks pass on both store views for guest and authenticated context, logout,
manual widget rendering in CMS/Page Builder Text markup, identity-free public page HTML, quantity
increases/decreases and checkout targets. Chrome additionally verified the real Hyva theme, widget
content, customer login, cart updates, shipping/payment method updates and payment-step navigation.
HTTP logout passed on both stores. Follow-up on 2026-11-05 visually confirmed Chrome's guest menu
and redirect from the protected account page to Customer Login after logout.
No order was placed, no coins were spent and no real payment was made.

See Test/Reports/personalizations-2026-11-04.md for exact scope and remaining compatibility limits.
These checks do not claim support for every third-party checkout, Hyva Checkout 3.x, or every
configurable/bundle customization. Variant/remount/failure cases currently have automated regression
coverage; repeat acceptance tests against a merchant's actual theme and checkout before deployment.

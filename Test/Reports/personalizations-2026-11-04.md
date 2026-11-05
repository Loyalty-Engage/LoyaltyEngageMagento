# Personalization Acceptance Results - 2026-11-04

## Environment

- Local demo only: https://magento.test/default/ and https://magento.test/hyva/.
- Magento Open Source 2.4.8, PHP 8.3.36, MariaDB 11.4.
- Luma and real Hyva Theme 1.5.3; real Hyva Checkout 1.3.15 / Magewire 1.13.3.
- Public ID test-1 rendered the tenant-provided text in the browser.
- Dependencies were installed in isolated demo volumes, not in the original project's vendor directory.
- Test accounts and ordinary test products only. No loyalty redemption, payment or order placement.

## Passed

- Full Magento dependency-injection compilation, including the Hyva modules.
- setup:upgrade and setup:db:status (all modules up to date).
- 163 PHP unit tests / 488 assertions; 25 JavaScript tests.
- Luma and Hyva: CMS/Page Builder Text-markup widget target renders through Magento's native widget filter.
- Luma and Hyva: cached public CMS HTML contains neither the test email nor its identifier hash.
- Luma and Hyva: guest context omits identifier; authenticated context returns only the current session's expected email hash.
- Luma and Hyva: logout removes the identifier on the next private context request.
- Luma and Hyva: cart quantities 1 -> 2 -> 1 produce context values 25 -> 50 -> 25 EUR.
- Luma and Hyva: native checkout page returns HTTP 200 with the automatic checkout target.
- Chrome / Hyva: visible automatic product/cart/checkout personalizations and manual CMS block.
- Chrome / Hyva: add-to-cart, cart update, login, shipping country/address update, shipping method update,
  navigation to Review & Payments and payment method selection.
- Private context responses have no-store cache protection and are not public-cacheable.
- No Magento exception.log was present after the completed flows.
- Follow-up 2026-11-05: Chrome's resulting guest state after logout was visually confirmed. The
  account menu shows Sign In/Create an Account, the minicart is empty, and opening the protected
  account URL redirects to Customer Login.

## Fixes Found During Acceptance

- Added hyva_checkout_index_index to checkout scope detection.
- Bound private context refresh to the real Magewire message.processed hook, once per page.
- Replaced CheckoutSession::getQuote() with read-only CartRepositoryInterface::getActive() in the
  personalization GET endpoint; expired quotes do not create or mutate a cart.
- Protected the frontend against synchronously blocked fetch calls.

## Limits And Deployment Notes

- The CMS fixture used saved Page Builder-compatible Text markup with a widget directive; a full
  drag-and-drop authoring workflow in the admin editor was not exercised.
- Real configurable/bundle variant acceptance, browser back-forward cache behavior, concurrent customer
  sessions, additional currencies and third-party checkout integrations were not exhaustively tested.
  Relevant deterministic cases have unit/frontend regression coverage.
- Hyva Checkout 3.x was not installed or tested; do not infer 3.x compatibility from the 1.3.15 result.
- PHP Composer dependency audit reported no advisories for the supplemental packages. Two high npm
  build-tool findings were resolved with non-breaking npm audit fix updates in the demo. Two low
  findings remain in Hyva's deepmerge build-tool chain; no forced downgrade was applied. Build-only
  dependencies are not shipped as part of this Magento module.
- Original API credentials were not restored in the rebuilt demo. This is personalization acceptance,
  not a fresh end-to-end certification of remote purchase/return/redemption flows.
- No Git push, tag or release was performed as part of these checks.

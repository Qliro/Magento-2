
# Change Log

## [1.7.12] - 2026-08-21

### Fixed

- A callback that arrives without a Qliro order id no longer loads an unrelated customer's quote. `qliro_order_id` is an integer column, so `getByField()` turned an empty lookup value into `WHERE qliro_order_id = ''`, MySQL cast that to `0`, and the filter matched any active link that has no Qliro order yet, of which the country selector and a failed order creation both leave plenty. `ShippingMethod::get()` then wrote the incoming payload address into that stranger's quote, saved it, and answered Qliro with shipping methods priced from a foreign cart. Every lookup now rejects `null`, an empty string and a zero before the query is issued. Reported by Outland (PLIN-378)
- Callback URLs handed to Qliro no longer carry a slash before the query string. Magento appends one after the action name, so the URL read `.../shippingMethods/?token=...`, setups that strip the slash answer with a redirect, and a client that does not resend the body on a redirect turns the callback into a POST with an empty body. That is how the callback above ended up with no order id in the first place (PLIN-378)
- `Repository::get()` filters on the `link_id` column. It passed `null` as the field name, so its active-links branch could not work at all (PLIN-378)
- Shipping methods stayed missing on a store view whose country is not the one the checkout was created with, Denmark in the report while Sweden worked. The country on the quote is written once, when the Qliro order is created, and nothing corrected it afterwards: `AddressConverter` filled an empty country but kept an existing one, and `CreateRequestBuilder` stamps a country onto both quote addresses before any address is known, so the guard was permanently closed. Postcode, city and street were updated as the buyer typed while the country stayed at its initial guess, Magento rated the carrier for Sweden on a Danish postcode and collected no rate at all. The country Qliro reports in the shipping methods callback now replaces a stored country that differs from it. Reported by Vajper (PLIN-376)
- The default country `ShippingMethod::update()` falls back to is resolved for the quote's store rather than the current one, and the store to website to default cascade it walked by hand is what `ScopeConfig` already does (PLIN-376)
- `CreateRequestBuilder` resolves its default country for the quote's store too. Hardening rather than a fix for the report above, the builder runs in a browser request where the current store is correct (PLIN-376)
- The callback token is cached per store. `UrlBuilder` is a singleton and the token is signed with the store's API credentials, so one token per process handed stores 2..n a token signed by the first store the recurring orders cron emulated, and `verifyToken()` rejected those pushes with a merchant id mismatch (PLIN-378)
- A lookup value that is not a positive integer is rejected on the `link_id`, `quote_id`, `qliro_order_id` and `order_id` columns. Rejecting the empty ones was not enough, MySQL reads `'abc'` as `0` just as it reads `''`, so a garbled id could still match a link that has no Qliro order yet (PLIN-378)
- Callback URLs build their query string themselves instead of going through the shared query params resolver. That resolver is a singleton and keeps what it is given, so the callback token was appended to other URLs generated in the same request (PLIN-378)
- A rejected link lookup names the value it rejected. The message said "empty" for anything unusable, which is what sent the investigation after the wrong cause once already (PLIN-378)

### Changed

- `qliro_order_id` on `qliroone_link` is nullable, and a data patch turns the zeroes already stored into null, so a comparison against an empty value can no longer match a link that has no Qliro order. The patch walks the table in batches of 5000, the zeroes are the abandoned checkouts and on a long lived shop they are most of the rows (PLIN-378)
- A callback with no `OrderId` in the payload is declined in the controller and logged as a missing order id. Qliro still receives `PostalCodeIsNotSupported`, the only reason the callback response defines, but the module log now names the real cause. The report above took a week to trace because the log named the postal code (PLIN-378)
- The callback URL logic lives in `Service\Callback\UrlBuilder` instead of being duplicated in the two create request builders (PLIN-378)

## [1.7.11] - 2026-08-19

### Fixed

- Shipping methods stayed missing until the checkout page was reloaded, for customers whose address Qliro already has on file. Qliro sends `Address: {"isMasked": true}` in the browser `updateCustomer` payload, so the quote cannot learn the address from it, and the unmasked address only arrives when the module fetches the order over the merchant API. `QliroOrder::get()` pushed the order update, which carries `AvailableShippingMethods`, before that fetch, so the update was always built from a quote with no address and Qliro received order items and nothing else. The address landed on the quote a moment later, which is why a reload showed the methods. The update is now pushed again when the fetched order actually changed the quote. (PLIN-376)

### Changed

- The converters report a change rather than the presence of a value, so a repeated payload no longer counts as an update. Without this the fix above would push an order update to Qliro on every checkout request (PLIN-376)

## [1.7.10] - 2026-08-18

### Fixed

- Shipping methods were missing on the first attempt for a new customer, and appeared after a reload or an address edit. One chain with two links: (PLIN-376)
  - `CustomerConverter` applied nothing at all, the address included, when `Customer.Email` was absent. Qliro's `onCustomerInfoChanged` delivers the address from the personal number lookup before the email is typed, so on the first event no postcode reached the quote, while `updateCustomer` still answered `OK`. Returning customers have the email from the first event, which is why this only reproduced for new ones. Email and address are now applied independently, and the address write no longer depends on the email.
  - The frontend then called Magento's `checkoutDataResolver.resolveShippingAddress()`, which resolves only from checkout local storage and the customer address book. Both are empty for a first time customer, so an empty address was selected. That assignment both estimated shipping on `postcode: null` and was the only trigger that rebuilds the Qliro order, so `AvailableShippingMethods` was rebuilt from an address-less quote and the checkout got `DeclineReason: POSTAL_CODE`. `updateCustomer` now returns the address it stored on the quote and the frontend selects that one instead.
- `updateCustomer` no longer answers `OK` after applying nothing. The converters report whether anything was written, and a payload that changed nothing is logged (PLIN-376)

### Added

- The shipping methods callback falls back to `Customer.Address` when the payload carries no `ShippingAddress`. Hardening rather than a fix for the report above, the callback is a server to server request that is not ordered against the browser call storing the address, so it should not depend on that address already being on the quote (PLIN-376)
- A decline from the shipping methods callback is logged with the postcode, country and number of collected rates, so an empty method list can be diagnosed from the module log alone (PLIN-376)

## [1.7.9] - 2026-08-16

### Fixed

- Order-management calls now target the Qliro order the order was placed against. `applyQliroOrderStatus()` re-loaded the link by Magento order id, which can return a stale/foreign row after a store DB reset/restore reuses an order id; `updatemerchantreference` was then sent to another merchant's Qliro order (`MERCHANT_MISMATCH` / `ORDER_NOT_FOUND`) and the placed order kept its temporary generated reference (e.g. `Z11kwQ` instead of `3000000020`). The callers now pass the link they already resolved by Qliro order id. (PLIN-373)
- A failed `updatemerchantreference` is now logged as a failure instead of "New merchant reference was assigned". `updateMerchantReference()` returns `null` on any API error, which the caller logged as success. The call stays fire-once (it runs inside a Qliro notification handler on every status callback, so it must not re-attempt or write to the order on each call), but the log now tells the truth so a stuck reference is visible. (PLIN-373)

## [1.7.8] - 2026-08-14

### Fixed

- Discount line VAT rate is now rounded to two decimals. A discount whose ex VAT amount does not land on whole öre produced a rate like `24.137931034483`, which the Qliro API rejects with `SYSTEM_ERROR`, "Input must have no more than two decimal places", so the order could neither be created nor updated and the checkout showed "Cannot fetch Qliro One order." (PLIN-358, GitHub issue #122)

## [1.7.7] - 2026-08-06

### Fixed

- Order item-limit check now counts the number of order lines actually sent to Qliro (from `OrderItemsBuilder`) instead of summing item quantities over the visible quote items. A single product with a high quantity is one line and is no longer wrongly rejected, and bundle child lines are counted, so the check matches the real payload (PLIN-305).

## [1.7.6] - 2026-06-25

### Fixed

- Fixed credit memo refunds failing on orders split across multiple captures (e.g. mixed physical and virtual products), where sending several `AddItemsToInvoice` additions at once caused the PSP to reject all but the first with a concurrent-reversal conflict
- Fixed captured amount being recorded for a capture even when its reversal was rejected by the PSP; refunded amounts are now stored only after the success callback
- Fixed the invoice fee being refunded too early on split-capture orders; the fee is now refunded only once the whole order is fully refunded, not when a single invoice is fully refunded while items remain
- Fixed product line VAT rate being sent as `0` to Qliro when the store tax rate could not be resolved from the default tax destination; the rate is now taken from the quote item's calculated tax percent

### Added

- Added per-capture captured-amount tracking on payment transactions, used as the basis for refund allocation
- Added sequential, callback-gated refund processing that sends one reversal at a time and waits for confirmation before sending the next
- Added refund allocation across capture transactions based on the amount left in each capture

### Changed

- Switched credit memo refunds to Qliro's `AddItemsToInvoice` endpoint (previously `returnitems`)
- Refund amounts are now spread across the correct Qliro captures when a credit memo exceeds a single capture
- Removed unused constructor dependencies in order management status handlers and the order management API client

### Removed

- Removed the legacy `returnitems`-based refund implementation, now superseded by `AddItemsToInvoice`

## [1.7.5] - 2026-05-13

### Fixed

- Fixed race conditions in Qliro callback handling for simultaneous `OnHold` and `Completed` statuses
- Prevented stale callbacks from overriding the final Magento order state
- Improved concurrent order creation handling by returning `Order creation pending` instead of failing requests
- Fixed deadlocks in `qliroone_log` table during concurrent callback processing
- Prevented HTTP 500 errors caused by logging failures during callback execution
- Improved recovery flow for outdated or invalid Qliro order links
- Enhanced Qliro order state synchronization and hash resolution logic
- Improved uniqueness handling for generated merchant reference hashes

### Added

- Added support for using Magento Increment ID as Qliro merchant reference
- Added refund handler for Qliro order management status updates
- Added modular hash resolvers for merchant reference generation

### Changed

- Refactored Qliro order management and hash resolution architecture
- Simplified link creation and state reload handling
- Improved code quality and reduced internal redundancy

## [1.7.4] - 2026-04-01

### Fixed
- Fixed incorrect version in `composer.json` from previous release
- Ensured proper versioning for Composer distribution

## [1.7.3] - 2026-03-31

### Fixed
- Missing shipping address/country id
- Credit memo error during order cancellation
- Missing SKU values in order items
- Module admin configs error during download

### Added
- Language support according to official documentation
- X-platform header in all qliro requests

## [1.7.2] - 2026-03-18

### Fixed
- Fixed the issue with the missing SKU values in order items

## [1.7.1] - 2026-03-03

### Fixed

- TypeError in validate callback when item metadata is null (`Item::setMetadata` expects array)

## [1.7.0] - 2026-02-06

### Fixed

- Fixed the issue with combining virtual products with non-virtual products in the same order
- Changed MerchantReference format sent to Qliro
- Capture full or partial shipments and/or invoices where order items are provided in the wrong sequence

### Added

- Support for PHP 8.4
- Limitation of items in the order to be not more than 200

## [1.6.9] - 2026-01-26

### Fixed

- Resolved an issue with order refunds caused by empty discount and fee items in the refund request
- Fixed an issue where duplicate orders could be created during checkout in multi-node Magento application setups
- Prevented order items from being modified after payment initiation
- Fixed the `400 ORDER_EXPIRED: Unable to update as too much time has passed after order creation time` error
- Resolved the `Not enough items for sale` error for quote items with a quantity of 1 in stock
- Fixed price calculation issues for Table Rates, Ingrid, and nShift shipping methods

### Removed

- Removed quote total recollection (`recalculateAndSaveQuote`) during validation requests to prevent deadlocks

### Added

- Improved logging

## [1.6.8] - 2026-01-05

### Fixed
- Issue with checkout not being loaded

## [1.6.7] - 2025-12-24

### Fixed
- Improved logging during comparing items

### Added
- VAT Rate to Qliro Order API
- Improved logging, ability to download logs from an admin panel

## [1.6.6] - 2025-10-31

### Fixed
- Prevented orders from being created with an empty payment method by improving handling of refused Qliro orders

## [1.6.5] - 2025-10-24

### Added
- Enhanced virtual product handling: improved configuration and logic so that checkouts containing only virtual products no longer require shipping, ensuring a smoother checkout experience and accurate payment method visibility.

### Fixed
- Improved order creation reliability: resolved an issue where some checkouts failed to create orders, ensuring consistent order generation and proper callback handling.
- Addressed Magento 2.4.8 compatibility issue: fixed a **City** field validation error introduced in the new version.
- Prevented unintended order cancellation when a user navigates back to the cart page during checkout, ensuring order status remains stable throughout the session.

### Security
- Updated dependencies and resolved security alerts flagged by Dependabot to maintain module integrity and compliance.


## [1.6.4] - 2025-08-29

### Added

- Added the possibility to combine native magento checkout with QliroOne as a payment option in it. New configuration "Show as payment method" introduced with related functionality.
- Added extra order validation checks to the Order Validation Callback. Introduced `SubmitQuoteValidator` to ensure more reliable quote handling during validation.
- Added logging for skipped shipping method ajax operations, providing detailed reasons for the skips.
- Wiki documentation added https://github.com/Qliro/Magento-2/wiki
- Added status history comments for refused orders to improve visibility.
- Added clearer explanations for Ingrid and nShift admin configuration to improve usability.

### Fixed

- Adjusted price calculations based on tax configuration. Updated `OrderSourceProvider` and `QuoteSourceProvider` to factor in store-specific tax configurations when calculating prices.
- Fixed address and email locking for logged-in users. Disable address locking for the logged-in users with preset address. Enable email locking for the logged-in users
- Fixed native magento `flatrate` shipping method price calculation with `per item` price type
- Fixed the authorization token expiration error
- Fixed the delayed (1h) order creation issue 

### Changed

- Simplify README by replacing detailed content with links to the Wiki. Streamlined documentation and added direct references for setup, configuration, customization, and troubleshooting, etc.
- Updated `QLIRO_POLL_VS_CHECKOUT_STATUS_TIMEOUT_FINAL` constant value from 3600 seconds to 180 seconds to modify final checkout status timeout duration which prevent delayed order creation in magento.
- Refactor security token classes and update type hints
- Increased callback authorization token expiration time from 4 to 3 years, according to EU law.
- Refined order cancellation flow for refused checkouts

## [1.6.3] - 2025-06-09

### Added

- Added 2.4.8 magneto version support

### Removed

- Remove dynamic log level configuration support which breaks `monolog/monolog` api 3 compatibility

## [1.6.2] - 2025-05-15

### Fixed

- Removed redundant `setFrequencyOption` methods and updated `setNextOrderDate` type hints.
- Corrected a typo in `post` method argument in `ApiServiceInterface`.
- Replaced `CommandList` with `CommandListInterface` in the DI configuration to align with Magento framework standards. This change ensures better compatibility and adherence to interface-driven programming practices
- Fixed recursion in `\Qliro\QliroOne\Model\Product\Type\OrderSourceProvider::getStoreId` and `\Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider::getStoreId` methods

### Added

- Enforce length limits on shipping method attributes. Added logic to shorten display name, descriptions, and brand if they exceed predefined length limits. Introduced constants for maximum lengths and a function `shortenIfTooLong` to handle string truncation with a suffix. Updated relevant method signatures for clarity and consistency. 

## [1.6.1] - 2025-05-07

### Fixed

- Fix db_schema.xml file. Remove duplicated index definition and unnecessary 'length' attributes from specific columns

## [1.6.0] - 2025-05-07

### Added
- Added invoice refund functionality
- Start CNANGELOG

### Changed

- Changed texts for several warnings, notices, and exceptions to avoid misunderstanding during observing logs
- Added exception logging on empty checkout
- Increased Ajax token expiration time
- Added extra logging for expired callback tokens

### Fixed

- Fix the setup schema scripts, which were not fully initiated during the first module install, which led to incomplete module setup and broken checkout
- Fix shipping price collection for weight-based shipping methods (table rate)
- Fix nShift and ingrid tax issue for Magento Commerce versions on saving shipping price and shipping methods (`AJAX:UPDATE_SHIPPING_METHOD` && `AJAX:UPDATE_SHIPPING_PRICE` requests)
- Fix `qliroone:api:updateorder` CLI command compatibility with the latest magento versions
- Remove hardcoded values from `qliroone:api:test` CLI command
- Fix the order cancellation bug during unpredictable  system failures

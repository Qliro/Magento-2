
# Change Log

## [1.7.20] - 2026-09-01

### Changed

- The admin and the customer order view show the payment method name Qliro sent instead of the raw method code, falling back to the code when no name was stored. The admin keeps the code in its own row for support, and only when it differs from the name. The printed invoice and credit memo are unchanged: that template prints neither, see PLIN-374 follow-up. Qliro keeps adding method codes, the six `QLIROPAYLATER_*` ones from the Ironman rollout among them, and `QLIROPAYLATER_INVOICE30` is not something a merchant can read (PLIN-374)
- Every value the order view prints is escaped on output. The method rows were printed raw, and so were the Qliro order id and the Qliro reference, in both the admin and the frontend template. All of them come from the payment's additional information, which is filled from what Qliro sends, so leaving half of them raw only made it look deliberate. The warning text is escaped too, though that branch cannot currently render, see PLIN-374 follow-up (PLIN-374)
- The order view reads the additional-information keys through the `Config` constants the writer uses, instead of repeating the literals (PLIN-374)

### Added

- Unit tests pinning that a payment method code passes through unchanged, for the six Ironman codes and for a legacy one, and that the name is returned as a string whatever the payload carried. The Ironman rollout rests on these needing no code change in the module, so it is now a test rather than an assertion (PLIN-374)

## [1.7.19] - 2026-08-31

### Fixed

- The discount line now carries the VAT of the discount in a store whose catalog prices exclude tax and whose tax is calculated after the discount, which is Magento's own default. `Magento/Tax/Model/Calculation/UnitBaseCalculator.php` hardcodes `discount_tax_compensation_amount` to 0 in that configuration and hands the discount over as an ex VAT figure, while the tax it charges does drop with the discount. The module read the missing compensation as "no VAT on this discount" and sent the same number on both price fields with `VatRate: 0`, so the Qliro order total stood above Magento's grand total by exactly the VAT of the discount, 2.50 on a 10.00 discount at 25 percent, and that is what the customer paid. The VAT now comes from the totals Magento already collected: the inc VAT subtotal and shipping are taxed before the discount and `tax_amount` is taxed after it, so their difference is the discount's VAT to the öre, also for a cart mixing several VAT rates and for a rule that discounts the shipping as well. Grossing the discount up with the cart's average rate was the alternative and it is only exact for a single rate cart (PLIN-360)
- Nothing changes in the three configurations that were already right: prices including tax with tax after the discount still takes the VAT part Magento states, and tax calculated before the discount still sends no VAT at all, because there the VAT does not move with the discount (PLIN-360)
- The shipping line of a capture carries the shipping VAT the checkout reserved. `ShippingFeeHandler` built it from `shipping_amount + shipping_tax_amount`, and `shipping_tax_amount` is the tax after the discount, while the checkout sends the shipping method at `shipping_incl_tax`, the tax before it. A rule that also discounts the shipping therefore left the capture short by the VAT the discount took off the shipping, and with the discount line now adding that VAT back it would have been subtracted twice. The line is built from `shipping_incl_tax`, falling back to the old sum on an order that has none (PLIN-360)

### Changed

- The inc and ex VAT amounts of the discount line, and the VAT rate that describes them, are resolved in one place, `Model/QliroOrder/DiscountAmountResolver.php`, for the checkout and for order management alike. It replaces the `getDiscountExclVat()` and `calculateVatRate()` copies that both `AppliedRulesHandler` classes carried (PLIN-360)
- A discount VAT larger than the cart's own VAT rates could produce is dropped rather than sent, and the store says so in `qliroone.log` with the amounts it was derived from. The difference the VAT is read out of describes the discount only while the lines and the shipping are the only things carrying tax, so another total collector taxing something the subtotals do not account for would push it either way, and the overcharging way is the one the customer feels. The ceiling is the highest rate among the order lines, with the rates the subtotals and the shipping imply added to it, so a store where Magento left no tax percent on the items still gets a ceiling rather than losing the VAT (PLIN-360)
- The discount VAT is added back only for an order whose reservation carries it. Qliro rejects a capture whose lines disagree with the reservation, `INVALID_ITEM`, and rejects it terminally, so an order reserved before this release would have become uncapturable rather than merely over-charged: its reservation holds the discount VAT free and its capture would have asked for 2.50 less on a 10.00 discount at 25 percent. The module now stamps `qliro_discount_carries_vat` on the payment when it places an order, and a capture without that stamp reproduces the line the reservation was built with. Orders open at upgrade time keep the over-charge they were reserved with, which is the only figure Qliro will accept for them, and they capture (PLIN-360)

## [1.7.18] - 2026-08-27

### Fixed

- Shipping methods were not loaded at all on a store view whose quote already carried the full address. The frontend skipped the address sync whenever the incoming address matched the quote, but an address equal to the quote does not mean the rates behind it were ever calculated: selecting the address is what triggers the rate request, so on such a store no request was made, `shippingRates()` stayed empty and no delivery methods appeared until the customer edited the address or reloaded the page. The skip now also requires the rates to exist. Reported on a Danish store view where the Swedish one worked (PLIN-376)
- `window.q1.lock()` no longer throws when the Qliro script has not initialised yet. It is created by Qliro's own script and nothing sequences that against the module's handlers, so `updateCart()` running during page initialisation raised "Cannot read properties of undefined (reading 'lock')". Because that happened inside a promise callback it abandoned everything after it in the same callback, including the address sync, so it could mask the defect above. Every use now goes through a guard, and a lock asked for too early is applied when the checkout announces itself rather than dropped (PLIN-376)
- Shipping methods still did not appear on the first attempt after 1.7.11, because the fix could not run. Qliro masks the address in the `updateCustomer` payload, so the store has nothing to store and the response carries no address. 1.7.10 made the frontend select an address only when there is one, and selecting an address is what triggers the Qliro order refresh that fetches the real address and pushes the shipping methods. With nothing to select, nothing was triggered and the refresh only happened on a page reload. The frontend now asks for that refresh directly when the store has no address yet (PLIN-376)
- The QliroOne iframe could stay locked for good. The frontend locks it while the store updates the quote and releases it when Qliro reports an order whose total matches, but the refresh above is asked for precisely when the store had nothing to apply, so there may be no quote change for Qliro to report and no order update to release the lock. A watchdog now releases it after ten seconds, and since `eager_checkout_refresh` defaults to off, locking is the default path rather than an edge case. It releases only a checkout that heard nothing at all: an order that did arrive and disagreed with the store keeps the iframe locked and says so, because unlocking there would let the customer pay a total the module already knows is wrong, and the mismatch message otherwise waits for the fourth one (PLIN-376)
- The mismatch counter and the total it compares against live at module scope instead of inside the order-update callback, and the callback is registered once. Each refresh used to get its own copy of both, so a later refresh compared Qliro's order against a total captured earlier and counted mismatches on a counter nothing else could reset, which is what raises the "totals don't match" message (PLIN-376)
- One quote update runs at a time, with one more after it if anything asked while it was in flight. `onCustomerInfoChanged` fires per masked-address payload and nothing on this side knows whether Qliro debounces it, so without the guard every payload took its own lock, its own round trip and its own watchdog, and they interleaved: an early response could set the expected total after a later one, and a watchdog could be armed for an update already superseded. The trailing run is what keeps the last state from being dropped (PLIN-376)
- A customer payload whose `address` arrives as a scalar no longer turns the log line that reports it into a 500. `array_keys()` raises a `TypeError`, which is an `Error` and escapes the surrounding `catch (\Exception)` (PLIN-376)

### Changed

- The `updateCustomer` log line now reports whether the quote ended up with a postcode and a country, and it is written on every call rather than only when nothing was applied. `CustomerConverter::convert()` returns true for a new email on its own, so a payload whose address was masked reported as applied while the quote still had nothing to rate shipping on, and the old line stayed silent in exactly that case. Field names only, never values, since the payload carries personal data (PLIN-376)

## [1.7.17] - 2026-08-26

### Fixed

- A capture is now sent once per order instead of once per enabled trigger. `capture_on_shipment` and `capture_on_invoice` are independent settings, and with both on one admin action fired both paths: the first `MarkItemsAsShipped` succeeded and took the money, the second was refused with `NO_ITEMS_LEFT_IN_RESERVATION`, and that refusal rolled the whole action back. The result was money captured at Qliro, no invoice in Magento, and an order that could never be closed, because the reservation was spent and every retry hit the same refusal. Qliro stamps a fresh `RequestId` per call, so it could not recognise the second submission as a repeat of the first. Reported by Skyla (PLIN-381)
- A capture Qliro reports as already shipped no longer fails the Magento document. The money moved, so the invoice must be allowed to complete rather than rolled back (PLIN-381)
- An accepted capture keeps its Qliro transaction id and its `OrderManagementStatus` row. Accepting one without them would have traded an unclosable order for an unrefundable one: `CaptureTransactionUpdater` looks the capture transaction up by that id to write `captured_amount`, and `CaptureRefundAllocator::getCaptures()` skips any capture that has none, so the invoice would record the capture under a txn_id of Magento's own making and the refund allocator would never see it. The id comes from the capture this module took in the same request, or from the transaction Qliro names in the refusal itself, and when neither is available the order says so in its status history instead of the miss surfacing later as a refund that does not work (PLIN-381)
- Order-management failures now say what Qliro actually answered. `Model/Api/Service.php` wraps every API failure in a `TerminalException`, so the branch in the API clients that formats Qliro's `ErrorCode` could never be reached and every refusal surfaced as "Request to Qliro One has failed", whether it was an already-shipped reservation, an order Qliro has never heard of, or a timeout (PLIN-381)
- A capture against a Qliro order id that Qliro does not know now names the id and says that retrying will not help, instead of the generic failure above. That is what a store sees for an order created against the other Qliro environment, test against production or the reverse (PLIN-381)

### Changed

- The admin help text for both capture triggers says to pick one, not both (PLIN-381)

## [1.7.15] - 2026-08-26

### Fixed

- The module now runs on Magento 2.4.9. Its console commands declared `execute()` without a return type, while 2.4.9 ships `symfony/console` 7 where the base method is declared `: int`. Magento loads every module's commands to build the CLI, so the incompatible declaration was a fatal error that took down all of `bin/magento`, and cron and `setup:upgrade` with it. Verified against real `magento/framework` 103.0.9: without this the module's own `Console/AbstractCommand.php` fatals on load, with it all eight commands load (PLIN-382)
- The QliroOne checkout no longer fails to load on PHP 8.5, which Magento 2.4.9 supports. `TypePoolHandler` indexed its handler pool with the value `TypeResolver` returns, which is `null` for an item it cannot resolve. PHP 8.5 deprecates a null array offset and developer mode raises that as an exception, so one unresolvable line ended the whole checkout with "Couldn't fetch the QliroOne order." on the storefront. Silent on 8.2 to 8.4, which is why it surfaced only on 2.4.9 stores (PLIN-382)

### Changed

- CI runs the unit suite on PHP 8.2, 8.3, 8.4 and 8.5, and the suite now fails on a deprecation. The checkout defect above was a deprecation on 8.5 only, so the single 8.4 job could not have caught it. The range is what the Magento releases inside `magento/framework: ^103.0` support: 2.4.7 and 2.4.8 still allow 8.2, only 2.4.9 dropped it (PLIN-382)

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

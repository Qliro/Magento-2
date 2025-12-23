
# Change Log

## [1.6.7] - 2025-12-22
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

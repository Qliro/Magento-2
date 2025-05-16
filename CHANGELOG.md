
# Change Log

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

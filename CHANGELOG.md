
# Change Log

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

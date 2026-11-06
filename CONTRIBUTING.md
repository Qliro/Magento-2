# Contributing

Conventions for code in this module. Tests enforce the ones marked so.

## Strict types (enforced)

Every `.php` file declares `strict_types=1` as its first statement, after the licence header where there is one. `Test/Unit/StrictTypesTest.php` fails on any file without it. Templates (`.phtml`) are left out.

Strict types apply to the calls a file makes, its return statements and its typed property writes. Magento calling into the module keeps the weak rules of Magento's own file for the arguments, but a native scalar return type in our file is now checked strictly. In our own calls, a value that does not have the declared scalar type is a `TypeError` instead of being converted silently. Magento hands out database values as strings (`$order->getGrandTotal()` is `"100.0000"`), `ScopeConfigInterface::getValue()` returns a string or null, `__()` returns a `Phrase`, and a docblock `@return float` is not a guarantee. Convert these where you make the call, `(float) $order->getGrandTotal()`, `(string) __('...')`, so the conversion is visible in the code.

Do not add or tighten native types on a method Magento calls: plugins, observers, `Api/` interfaces and their implementations, or anything that overrides a Magento class. Those signatures belong to Magento's contract.

## Constructors

Use constructor property promotion with `private readonly`:

```php
public function __construct(
    private readonly Config $config,
    private readonly LogManager $logManager
) {
}
```

Leave a property out of the promotion only when the class changes it after construction. A class that calls a parent constructor promotes its own dependencies and passes the parent's through.

Existing classes that assign in the constructor body are converted when their constructor is changed anyway, not in bulk.

## Docblocks

Use `@inheritDoc` for a method that implements or overrides a documented one.

## Checks (enforced in CI)

GitHub Actions runs the unit tests on PHP 8.2 to 8.5 and the static analysis once, on PHP 8.4. Run the same locally (`composer install` needs Marketplace keys in `auth.json`, or point `repositories` at `https://mirror.mage-os.org/` as CI does):

```
composer install
composer test
composer static-analysis
```

`composer static-analysis` installs the tools into `tools/` (their own composer project, so installing them never conflicts with Magento's dependencies) and runs:

- PHPStan at the level in `phpstan.neon.dist`, now 5: undefined methods and properties, wrong argument and return types. Magic `get*`/`set*`/`has*`/`uns*` calls on a `DataObject` are accepted, as Magento resolves them at runtime. Errors that existed when it was introduced are in `phpstan-baseline.neon`. New code adds nothing there. When you fix a baselined error, regenerate the baseline with `tools/vendor/bin/phpstan analyse --generate-baseline`. The tool versions are pinned in `tools/composer.lock`. To raise the level, run the next one, fix or baseline what it reports and change `level`.
- The Magento coding standard over all production code and templates, errors only. The few errors that existed when it was introduced are excluded per file in `phpcs.xml.dist`. Warnings are not checked; see them with `composer phpcs -- --warning-severity=5`.

`composer phpstan` and `composer phpcs` run one of them, after `composer tools`.

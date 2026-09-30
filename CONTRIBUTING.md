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

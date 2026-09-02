<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/**
 * Magento generates its *Factory classes from the DI compiler at runtime, so they do not exist
 * in a plain unit test run and cannot be mocked. Declare the ones our own namespace asks for,
 * and the Magento ones a constructor we test type hints on. This runs last, after Composer has
 * failed to find the class, so a factory that ships as real code is never shadowed.
 */
spl_autoload_register(static function (string $class): void {
    $generated = str_starts_with($class, 'Qliro\\QliroOne\\') || str_starts_with($class, 'Magento\\');

    if (!$generated || !str_ends_with($class, 'Factory')) {
        return;
    }

    $separator = strrpos($class, '\\');
    $namespace = substr($class, 0, $separator);
    $name = substr($class, $separator + 1);

    // phpcs:ignore Squiz.PHP.Eval.Discouraged
    eval("namespace {$namespace}; class {$name} { public function create(array \$data = []) { return null; } }");
});

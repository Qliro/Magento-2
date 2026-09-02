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
    if (!str_ends_with($class, 'Factory')) {
        return;
    }

    /*
     * A generated Magento factory always sits next to the type it builds, so that type is what
     * says the name is real. Without it any misspelled class ending in Factory would quietly
     * mock, and a test could pass against an API nothing implements.
     */
    $builds = substr($class, 0, -strlen('Factory'));
    $generated = str_starts_with($class, 'Qliro\\QliroOne\\')
        || (str_starts_with($class, 'Magento\\') && (class_exists($builds) || interface_exists($builds)));

    if (!$generated) {
        return;
    }

    $separator = strrpos($class, '\\');
    $namespace = substr($class, 0, $separator);
    $name = substr($class, $separator + 1);

    // phpcs:ignore Squiz.PHP.Eval.Discouraged
    eval("namespace {$namespace}; class {$name} { public function create(array \$data = []) { return null; } }");
});

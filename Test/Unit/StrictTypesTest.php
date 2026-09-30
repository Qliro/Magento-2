<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every PHP file of the module declares strict_types as its first statement (PLIN-371)
 */
class StrictTypesTest extends TestCase
{
    private const SKIPPED_DIRS = ['vendor', 'Test/tmp', '.git'];

    public function testEveryPhpFileDeclaresStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($root): bool {
                    $relative = substr($file->getPathname(), strlen($root) + 1);
                    return !in_array($relative, self::SKIPPED_DIRS, true);
                }
            )
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && !$this->declaresStrictTypes((string)file_get_contents($file->getPathname()))) {
                $missing[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($missing);
        $this->assertSame([], $missing, 'Add declare(strict_types=1); to these files');
    }

    private function declaresStrictTypes(string $source): bool
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn($token): bool => !is_array($token)
                || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $head = implode('', array_map(
            static fn($token): string => is_array($token) ? $token[1] : $token,
            array_slice($tokens, 0, 7)
        ));

        return $head === 'declare(strict_types=1);';
    }
}

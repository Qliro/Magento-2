<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use PHPUnit\Framework\TestCase;

/**
 * Only placing an order saves the quote with Magento's full address check. Every save while the
 * checkout is open goes through recalculateAndSaveOpenQuote(), or a company without a contact
 * name is refused on Magento 2.4.9.
 *
 * @see \Qliro\QliroOne\Model\Management\Quote::recalculateAndSaveOpenQuote
 */
class OpenQuoteSaveCallersTest extends TestCase
{
    private const PLAIN_SAVE_ALLOWED = [
        'Model/Management/PlaceOrder.php',
        'Model/Management/PlaceRecurringOrder.php',
        'Model/Management/Quote.php',
    ];

    public function testOnlyPlacementSavesWithTheFullCheck(): void
    {
        $root = dirname(__DIR__, 4);
        $callers = [];

        $files = new \AppendIterator();
        foreach (['Block', 'Console', 'Controller', 'Cron', 'Helper', 'Model', 'Observer', 'Plugin', 'Service'] as $dir) {
            if (is_dir($root . '/' . $dir)) {
                $files->append(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                    $root . '/' . $dir,
                    \FilesystemIterator::SKIP_DOTS
                )));
            }
        }
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('/->recalculateAndSaveQuote\s*\(/', (string)file_get_contents($file->getPathname()))) {
                $callers[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($callers);

        self::assertSame(self::PLAIN_SAVE_ALLOWED, $callers);
    }

    /** In Quote.php the plain save is called by the open one only */
    public function testQuoteManagementCallsThePlainSaveFromTheOpenOneOnly(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/Model/Management/Quote.php');

        self::assertSame(1, preg_match_all('/->recalculateAndSaveQuote\s*\(/', $source));
        self::assertGreaterThanOrEqual(3, preg_match_all('/->recalculateAndSaveOpenQuote\s*\(/', $source));
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Model\Method\Adapter;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;

/**
 * @see \Qliro\QliroOne\Model\Config::getLogRetentionDays()
 */
class ConfigLogRetentionTest extends TestCase
{
    /**
     * A value that is no window at all keeps every row rather than falling back to a window that
     * deletes: a typo in `app/etc/env.php` reaches the getter without passing the backend model,
     * and rows deleted from a merchant's log cannot be brought back.
     *
     * @dataProvider storedValueProvider
     */
    public function testReadsAWholeNumberOfDaysAndKeepsEveryRowOtherwise(mixed $stored, int $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::XML_PATH_LOG_RETENTION_DAYS, ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            ->willReturn($stored);

        self::assertSame($expected, $this->buildConfig($scopeConfig)->getLogRetentionDays());
    }

    public static function storedValueProvider(): array
    {
        return [
            'the default' => ['30', 30],
            'a window of its own' => ['7', 7],
            'an explicit zero keeps everything' => ['0', 0],
            'stray whitespace is not a different number' => [' 90 ', 90],
            'a blanked field keeps everything' => ['', 0],
            'nothing stored keeps everything' => [null, 0],
            'a negative number keeps everything' => ['-3', 0],
            'a fraction keeps everything' => ['1.5', 0],
            'a value no date can hold keeps everything' => [(string)(Config::MAX_LOG_RETENTION_DAYS + 1), 0],
        ];
    }

    /**
     * The field lives on the default scope only, so the read is pinned there and a store override
     * written past the admin form with config:set cannot steer the whole table.
     */
    public function testReadsTheDefaultScopeOnly(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with(Config::XML_PATH_LOG_RETENTION_DAYS, ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            ->willReturn('30');

        $this->buildConfig($scopeConfig)->getLogRetentionDays();
    }

    private function buildConfig(ScopeConfigInterface $scopeConfig): Config
    {
        return new Config(
            $this->createMock(Adapter::class),
            $scopeConfig,
            $this->createMock(Json::class),
            $this->createMock(DirectoryHelper::class),
            $this->createMock(CountryCollectionFactory::class)
        );
    }
}

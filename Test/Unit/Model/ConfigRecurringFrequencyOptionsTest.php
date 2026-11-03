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
 * @see \Qliro\QliroOne\Model\Config::getRecurringFrequencyOptions()
 */
class ConfigRecurringFrequencyOptionsTest extends TestCase
{
    /**
     * @dataProvider storedValueProvider
     */
    public function testItAlwaysGivesJson(mixed $stored, string $expected): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('getConfigData')
            ->with(Config::QLIROONE_RECURRING_FREQUENCY_OPTIONS, null)
            ->willReturn($stored);

        $config = new Config(
            $adapter,
            $this->createMock(ScopeConfigInterface::class),
            new Json(),
            $this->createMock(DirectoryHelper::class),
            $this->createMock(CountryCollectionFactory::class)
        );

        self::assertSame($expected, $config->getRecurringFrequencyOptions());
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function storedValueProvider(): array
    {
        $monthly = '{"_1":{"label":"Monthly","frequency":"1","time_unit":"month"}}';

        return [
            'nothing saved' => [null, '[]'],
            'an empty value' => ['', '[]'],
            'saved in the admin' => [$monthly, $monthly],
            'rows in config.php' => [
                ['_1' => ['label' => 'Monthly', 'frequency' => '1', 'time_unit' => 'month']],
                $monthly,
            ],
        ];
    }
}

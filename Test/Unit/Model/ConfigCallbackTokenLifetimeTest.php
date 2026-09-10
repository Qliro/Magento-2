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
 * @see \Qliro\QliroOne\Model\Config::getCallbackTokenLifetimeDays()
 */
class ConfigCallbackTokenLifetimeTest extends TestCase
{
    /**
     * A window that is not a whole number of days means the default, and one longer than a date
     * of any use is capped: a token that never expires is what this release exists to end.
     *
     * @dataProvider storedValueProvider
     */
    public function testReadsAWholeNumberOfDaysAndFallsBackToTheDefault(mixed $stored, int $expected): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('getConfigData')
            ->with(Config::QLIROONE_CALLBACK_TOKEN_LIFETIME_DAYS, null)
            ->willReturn($stored);

        $config = new Config(
            $adapter,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(Json::class),
            $this->createMock(DirectoryHelper::class),
            $this->createMock(CountryCollectionFactory::class)
        );

        self::assertSame($expected, $config->getCallbackTokenLifetimeDays());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function storedValueProvider(): array
    {
        return [
            'the shipped default' => ['1095', 1095],
            'a year' => ['365', 365],
            'a short window' => ['30', 30],
            'stray whitespace' => [' 30 ', 30],
            'the longest window' => ['1095', Config::MAX_CALLBACK_TOKEN_LIFETIME_DAYS],
            'longer than that is capped' => ['4000', Config::MAX_CALLBACK_TOKEN_LIFETIME_DAYS],
            'zero is not a window' => ['0', Config::DEFAULT_CALLBACK_TOKEN_LIFETIME_DAYS],
            'a blanked field' => ['', Config::DEFAULT_CALLBACK_TOKEN_LIFETIME_DAYS],
            'nothing stored' => [null, Config::DEFAULT_CALLBACK_TOKEN_LIFETIME_DAYS],
            'a negative number' => ['-30', Config::DEFAULT_CALLBACK_TOKEN_LIFETIME_DAYS],
            'a fraction' => ['1.5', Config::DEFAULT_CALLBACK_TOKEN_LIFETIME_DAYS],
        ];
    }
}

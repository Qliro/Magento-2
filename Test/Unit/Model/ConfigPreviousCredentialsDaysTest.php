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
 * @see \Qliro\QliroOne\Model\Config::getPreviousCredentialsDays()
 */
class ConfigPreviousCredentialsDaysTest extends TestCase
{
    /**
     * Zero is a choice here, it turns the grace off; anything unreadable is the default.
     *
     * @dataProvider storedValueProvider
     */
    public function testReadsWholeDaysWithZeroAllowed(mixed $stored, int $expected): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('getConfigData')
            ->with(Config::QLIROONE_PREVIOUS_CREDENTIALS_DAYS)
            ->willReturn($stored);

        $config = new Config(
            $adapter,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(Json::class),
            $this->createMock(DirectoryHelper::class),
            $this->createMock(CountryCollectionFactory::class)
        );

        self::assertSame($expected, $config->getPreviousCredentialsDays());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function storedValueProvider(): array
    {
        return [
            'the shipped default' => ['90', 90],
            'off' => ['0', 0],
            'stray whitespace' => [' 30 ', 30],
            'capped at the longest token lifetime' => ['4000', Config::MAX_CALLBACK_TOKEN_LIFETIME_DAYS],
            'a blanked field' => ['', Config::DEFAULT_PREVIOUS_CREDENTIALS_DAYS],
            'nothing stored' => [null, Config::DEFAULT_PREVIOUS_CREDENTIALS_DAYS],
            'negative' => ['-5', Config::DEFAULT_PREVIOUS_CREDENTIALS_DAYS],
            'not a number' => ['soon', Config::DEFAULT_PREVIOUS_CREDENTIALS_DAYS],
        ];
    }
}

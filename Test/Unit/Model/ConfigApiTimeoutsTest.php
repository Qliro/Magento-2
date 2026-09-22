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
 * @see \Qliro\QliroOne\Model\Config::getApiConnectTimeout()
 * @see \Qliro\QliroOne\Model\Config::getApiRequestTimeout()
 */
class ConfigApiTimeoutsTest extends TestCase
{
    /**
     * Guzzle reads 0 as "wait forever", which is the state these settings exist to end, so a
     * field that is not a positive whole number of seconds means the shipped default.
     *
     * @dataProvider storedValueProvider
     */
    public function testFallsBackToTheDefaultRatherThanToNoTimeout(mixed $stored, int $expected): void
    {
        $config = $this->config([Config::QLIROONE_INTERACTIVE_REQUEST_TIMEOUT => $stored]);

        self::assertSame($expected, $config->getApiRequestTimeout(Config::API_PROFILE_INTERACTIVE));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function storedValueProvider(): array
    {
        return [
            'the shipped default' => ['15', 15],
            'a shorter wait' => ['8', 8],
            'stray whitespace' => [' 8 ', 8],
            'the longest wait' => ['300', Config::MAX_API_TIMEOUT],
            'longer than that is capped' => ['3600', Config::MAX_API_TIMEOUT],
            'zero is no timeout at all' => ['0', Config::DEFAULT_INTERACTIVE_REQUEST_TIMEOUT],
            'a blanked field' => ['', Config::DEFAULT_INTERACTIVE_REQUEST_TIMEOUT],
            'nothing stored' => [null, Config::DEFAULT_INTERACTIVE_REQUEST_TIMEOUT],
            'a negative number' => ['-5', Config::DEFAULT_INTERACTIVE_REQUEST_TIMEOUT],
            'a fraction' => ['2.5', Config::DEFAULT_INTERACTIVE_REQUEST_TIMEOUT],
        ];
    }

    /**
     * The checkout waits in front of a customer and the order management calls do not, so the
     * two read fields of their own. Reading one for the other would hand the checkout the long
     * wait that only a capture can afford.
     */
    public function testEachCallTypeReadsItsOwnFields(): void
    {
        $config = $this->config([
            Config::QLIROONE_INTERACTIVE_CONNECT_TIMEOUT => '3',
            Config::QLIROONE_INTERACTIVE_REQUEST_TIMEOUT => '9',
            Config::QLIROONE_BACKGROUND_CONNECT_TIMEOUT => '7',
            Config::QLIROONE_BACKGROUND_REQUEST_TIMEOUT => '90',
        ]);

        self::assertSame(3, $config->getApiConnectTimeout(Config::API_PROFILE_INTERACTIVE));
        self::assertSame(9, $config->getApiRequestTimeout(Config::API_PROFILE_INTERACTIVE));
        self::assertSame(7, $config->getApiConnectTimeout(Config::API_PROFILE_BACKGROUND));
        self::assertSame(90, $config->getApiRequestTimeout(Config::API_PROFILE_BACKGROUND));
    }

    /**
     * The values are read for the store the call is being made for, not for whichever store the
     * request happens to be rendering.
     */
    public function testReadsTheFieldForTheStoreTheCallIsMadeFor(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects(self::once())
            ->method('getConfigData')
            ->with(Config::QLIROONE_INTERACTIVE_REQUEST_TIMEOUT, 7)
            ->willReturn('12');

        self::assertSame(12, $this->configWith($adapter)->getApiRequestTimeout(Config::API_PROFILE_INTERACTIVE, 7));
    }

    /**
     * A profile nobody configured is the checkout one, which is the shorter of the two: an
     * unknown call type must not be handed the wait only a capture can afford.
     */
    public function testAnUnknownCallTypeIsTreatedAsTheCheckout(): void
    {
        $config = $this->config([Config::QLIROONE_INTERACTIVE_REQUEST_TIMEOUT => '9']);

        self::assertSame(9, $config->getApiRequestTimeout('something else'));
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function config(array $stored): Config
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('getConfigData')->willReturnCallback(
            fn ($path) => $stored[$path] ?? null
        );

        return $this->configWith($adapter);
    }

    private function configWith(Adapter $adapter): Config
    {
        return new Config(
            $adapter,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(Json::class),
            $this->createMock(DirectoryHelper::class),
            $this->createMock(CountryCollectionFactory::class)
        );
    }
}

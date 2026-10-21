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
 * @see \Qliro\QliroOne\Model\Config::isEmbeddedIframeMode()
 */
class ConfigEmbeddedIframeModeTest extends TestCase
{
    /**
     * The iframe mode only exists on top of "show as payment method", so a mode left stored after
     * that was turned off is not the iframe mode
     *
     * @dataProvider storedValueProvider
     */
    public function testNeedsBothSettings(mixed $showAsPaymentMethod, mixed $renderMode, bool $expected): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('getConfigData')->willReturnMap([
            [Config::QLIROONE_SHOW_AS_PAYMENT_METHOD, null, $showAsPaymentMethod],
            [Config::QLIROONE_PAYMENT_METHOD_RENDER_MODE, null, $renderMode],
        ]);

        $config = new Config(
            $adapter,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(Json::class),
            $this->createMock(DirectoryHelper::class),
            $this->createMock(CountryCollectionFactory::class)
        );

        self::assertSame($expected, $config->isEmbeddedIframeMode());
    }

    /**
     * @return array<string, array{mixed, mixed, bool}>
     */
    public static function storedValueProvider(): array
    {
        return [
            'shown as a payment method in an iframe' => ['1', 'iframe', true],
            'an iframe mode left after turning that off' => ['0', 'iframe', false],
            'shown as a payment method by redirect' => ['1', 'redirect', false],
            'nothing stored' => [null, null, false],
        ];
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Store\Model\Information;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;
use Qliro\QliroOne\Model\QliroOrder\ShippingMethod;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder::filterToSelectedShippingMethod
 *
 * PLIN-419: the native checkout owns the delivery choice in the iframe mode, so the iframe is sent
 * one method and has nothing to pick. The filter sits in this builder because both the create
 * request and the quote update come through it, and a filter in only one of them would put the
 * picker back on the buyer's screen after the first cart change.
 */
class ShippingMethodsBuilderIframeTest extends TestCase
{
    private Config&MockObject $qliroConfig;
    private Address&MockObject $shippingAddress;
    private Quote&MockObject $quote;
    private ShippingMethodsBuilder $builder;

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);

        $this->shippingAddress = $this->createMock(Address::class);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $this->quote->method('getStoreId')->willReturn(1);

        $this->builder = new class (
            $this->createMock(UpdateShippingMethodsResponseInterfaceFactory::class),
            $this->createMock(ShippingMethodBuilder::class),
            $this->createMock(ManagerInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->qliroConfig,
            $this->createMock(LogManager::class),
            $this->createMock(Information::class),
            $this->createMock(ShippingConfig::class)
        ) extends ShippingMethodsBuilder {
            public function filter(array $shippingMethods): array
            {
                return $this->filterToSelectedShippingMethod($shippingMethods);
            }
        };

        $this->builder->setQuote($this->quote);
    }

    /**
     * The one method the buyer already chose is all Qliro is told about.
     */
    public function testTheIframeModeSendsOnlyTheSelectedMethod(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);
        $this->shippingAddress->method('getShippingMethod')->willReturn('flatrate_flatrate');

        $filtered = $this->builder->filter($this->methods(['freeshipping_freeshipping', 'flatrate_flatrate']));

        self::assertCount(1, $filtered);
        self::assertSame('flatrate_flatrate', $filtered[0]->getMerchantReference());
    }

    /**
     * The redirect mode is where Qliro shows the picker, so the whole list has to reach it.
     */
    public function testTheRedirectModeSendsEveryMethod(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(false);
        $this->shippingAddress->method('getShippingMethod')->willReturn('flatrate_flatrate');

        $filtered = $this->builder->filter($this->methods(['freeshipping_freeshipping', 'flatrate_flatrate']));

        self::assertCount(2, $filtered);
    }

    /**
     * The cost of delivery travels on this list and has no line of its own, so a list that cannot
     * be narrowed is sent whole. A wrong single entry would change what the buyer is charged.
     */
    public function testAnUnmatchedSelectionSendsEveryMethod(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);
        $this->shippingAddress->method('getShippingMethod')->willReturn('ups_ground');

        $filtered = $this->builder->filter($this->methods(['freeshipping_freeshipping', 'flatrate_flatrate']));

        self::assertCount(2, $filtered);
    }

    /**
     * Nothing chosen yet, for instance a virtual cart or a buyer who has not reached the delivery
     * step, leaves the list alone.
     */
    public function testNoSelectionSendsEveryMethod(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);
        $this->shippingAddress->method('getShippingMethod')->willReturn(null);

        $filtered = $this->builder->filter($this->methods(['freeshipping_freeshipping', 'flatrate_flatrate']));

        self::assertCount(2, $filtered);
    }

    /**
     * An empty list is a decline the caller already handles, and it is not this filter's to change.
     */
    public function testAnEmptyListIsUntouched(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);

        self::assertSame([], $this->builder->filter([]));
    }

    /**
     * @param string[] $references
     * @return ShippingMethod[]
     */
    private function methods(array $references): array
    {
        return array_map(
            static function (string $reference): ShippingMethod {
                $method = new ShippingMethod();
                $method->setMerchantReference($reference);

                return $method;
            },
            $references
        );
    }
}

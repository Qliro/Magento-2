<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface;
use Qliro\QliroOne\Api\Data\QliroOrderUpdateRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderUpdateRequestInterfaceFactory;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingConfigBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\UpdateRequest;

/**
 * What a changed cart is pushed to Qliro as. The order Qliro shows the buyer is the last update
 * it was sent, so the update has to carry the lines and the delivery options the create carried.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder
 */
class UpdateRequestBuilderTest extends TestCase
{
    /**
     * The update carries the lines of the cart as it stands now, and the delivery options rated
     * for it. An update that dropped either would leave Qliro showing the cart before the change.
     */
    public function testCarriesTheLinesAndTheDeliveryOptionsOfTheChangedCart(): void
    {
        $line = (new Item())->setMerchantReference('SKU-1')->setPricePerItemIncVat(125.0);
        $shippingMethod = ['flatrate_flatrate'];

        $request = $this->builder($line, $shippingMethod)->setQuote($this->createMock(Quote::class))->create();

        self::assertSame([$line], $request->getOrderItems());
        self::assertSame($shippingMethod, $request->getAvailableShippingMethods());
        self::assertTrue($request->getRequireIdentityVerification());
    }

    /**
     * A store that configures no delivery integration is not given one at all. The field defaults
     * to null on the request, so the builder has to leave the setter alone rather than call it
     * with nothing, which is what an integration reading the request back would see.
     */
    public function testLeavesOutTheDeliveryConfigurationWhenTheStoreHasNone(): void
    {
        $request = $this->createMock(QliroOrderUpdateRequestInterface::class);
        $request->expects(self::never())->method('setShippingConfiguration');

        $this->builder(null, [], null, $request)->setQuote($this->createMock(Quote::class))->create();
    }

    public function testCarriesTheDeliveryConfigurationWhenTheStoreHasOne(): void
    {
        $shippingConfig = $this->createMock(QliroOrderShippingConfigInterface::class);

        $request = $this->builder(null, [], $shippingConfig)
            ->setQuote($this->createMock(Quote::class))
            ->create();

        self::assertSame($shippingConfig, $request->getShippingConfiguration());
    }

    public function testRefusesToBuildWithoutACart(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder()->create();
    }

    private function builder(
        ?Item $line = null,
        array $shippingMethods = [],
        ?QliroOrderShippingConfigInterface $shippingConfig = null,
        ?QliroOrderUpdateRequestInterface $request = null
    ): UpdateRequestBuilder {
        $requestFactory = $this->createMock(QliroOrderUpdateRequestInterfaceFactory::class);
        $requestFactory->method('create')
            ->willReturnCallback(static fn(): QliroOrderUpdateRequestInterface => $request ?? new UpdateRequest());

        $orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $orderItemsBuilder->method('setQuote')->willReturnSelf();
        $orderItemsBuilder->method('create')->willReturn($line ? [$line] : []);

        $shippingMethodsResponse = $this->createMock(UpdateShippingMethodsResponseInterface::class);
        $shippingMethodsResponse->method('getAvailableShippingMethods')->willReturn($shippingMethods);

        $shippingMethodsBuilder = $this->createMock(ShippingMethodsBuilder::class);
        $shippingMethodsBuilder->method('setQuote')->willReturnSelf();
        $shippingMethodsBuilder->method('create')->willReturn($shippingMethodsResponse);

        $shippingConfigBuilder = $this->createMock(ShippingConfigBuilder::class);
        $shippingConfigBuilder->method('setQuote')->willReturnSelf();
        $shippingConfigBuilder->method('create')->willReturn($shippingConfig);

        $qliroConfig = $this->createMock(Config::class);
        $qliroConfig->method('requireIdentityVerification')->willReturn(true);

        return new UpdateRequestBuilder(
            $requestFactory,
            $qliroConfig,
            $this->createMock(ScopeConfigInterface::class),
            $orderItemsBuilder,
            $shippingMethodsBuilder,
            $shippingConfigBuilder
        );
    }
}

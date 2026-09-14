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
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Tax\Helper\Data as TaxHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterfaceFactory;
use Qliro\QliroOne\Api\ShippingMethodBrandResolverInterface;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder;
use Qliro\QliroOne\Model\QliroOrder\ShippingMethod;

/**
 * The delivery options a cart is offered in the Qliro checkout, one per shipping rate. The price
 * here is what the buyer is charged for delivery, and the amounts go out formatted to the two
 * decimals Qliro accepts.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder
 */
class ShippingMethodBuilderTest extends TestCase
{
    private const SELECTED = 'flatrate_flatrate';

    /**
     * The option the cart has selected is sent with the totals the cart collected for it. Those
     * are what the order is placed with, and re-rating it here could answer with another amount.
     */
    public function testSendsTheSelectedOptionWithTheTotalsTheCartCollected(): void
    {
        $method = $this->build(
            $this->rate(self::SELECTED, 49.0),
            $this->address(self::SELECTED, 62.5, 50.0)
        );

        self::assertSame('62.50', $method->getPriceIncVat());
        self::assertSame('50.00', $method->getPriceExVat());
        self::assertSame(self::SELECTED, $method->getMerchantReference());
    }

    /**
     * Every other option is priced from its own rate, taxed for the address the cart is going to.
     */
    public function testPricesAnOptionTheCartHasNotSelectedFromItsOwnRate(): void
    {
        $method = $this->build(
            $this->rate('ups_ground', 80.0),
            $this->address(self::SELECTED, 62.5, 50.0)
        );

        self::assertSame('100.00', $method->getPriceIncVat());
        self::assertSame('80.00', $method->getPriceExVat());
    }

    /**
     * A cart that has selected an option but collected no total for it is rated like any other,
     * which is also what free delivery looks like on the address.
     */
    public function testRatesTheSelectedOptionWhenTheCartCollectedNoTotalForIt(): void
    {
        $method = $this->build(
            $this->rate(self::SELECTED, 80.0),
            $this->address(self::SELECTED, 0.0, 0.0)
        );

        self::assertSame('100.00', $method->getPriceIncVat());
        self::assertSame('80.00', $method->getPriceExVat());
    }

    /**
     * The amounts carry the two decimals Qliro accepts, whatever the rating answered with.
     */
    public function testRoundsTheAmountsToTheTwoDecimalsQliroAccepts(): void
    {
        $method = $this->build(
            $this->rate(self::SELECTED, 49.0),
            $this->address(self::SELECTED, 61.9875, 49.59)
        );

        self::assertSame('61.99', $method->getPriceIncVat());
        self::assertSame('49.59', $method->getPriceExVat());
    }

    /**
     * The buyer sees the method title, and the carrier title when the method has none.
     */
    public function testNamesTheOptionAfterTheMethodAndFallsBackToTheCarrier(): void
    {
        $named = $this->build($this->rate(self::SELECTED, 49.0), $this->address(self::SELECTED, 61.25, 49.0));
        self::assertSame('Fixed', $named->getDisplayName());
        self::assertSame(['Flat Rate', 'Delivered in 2 days'], $named->getDescriptions());

        $rate = $this->rate(self::SELECTED, 49.0, ['methodTitle' => null, 'methodDescription' => null]);
        $bare = $this->build($rate, $this->address(self::SELECTED, 61.25, 49.0));
        self::assertSame('Flat Rate', $bare->getDisplayName());
        self::assertSame(['Flat Rate'], $bare->getDescriptions());
    }

    /**
     * The builder is one shared instance, so it releases the cart it built from.
     */
    public function testReleasesTheCartItBuiltFrom(): void
    {
        $builder = $this->builtOnce();

        $this->expectException(\LogicException::class);
        $builder->create();
    }

    /**
     * And it releases the rate: the next option must not be priced from the one before it, which
     * a cart set for a second build would otherwise be.
     */
    public function testReleasesTheRateItBuiltFrom(): void
    {
        $builder = $this->builtOnce();
        $builder->setQuote($this->quote($this->address(self::SELECTED, 61.25, 49.0)));

        $this->expectException(\LogicException::class);
        $builder->create();
    }

    public function testRefusesToBuildWithoutARate(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder()->setQuote($this->quote($this->address(self::SELECTED, 61.25, 49.0)))->create();
    }

    public function testRefusesToBuildWithoutACart(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder()->setShippingRate($this->rate(self::SELECTED, 49.0))->create();
    }

    /**
     * A builder that has already built one option, with nothing set for a second
     */
    private function builtOnce(): ShippingMethodBuilder
    {
        $builder = $this->builder();
        $builder->setQuote($this->quote($this->address(self::SELECTED, 61.25, 49.0)))
            ->setShippingRate($this->rate(self::SELECTED, 49.0))
            ->create();

        return $builder;
    }

    private function build(Rate $rate, Address $address): ShippingMethod
    {
        return $this->builder()->setQuote($this->quote($address))->setShippingRate($rate)->create();
    }

    private function builder(): ShippingMethodBuilder
    {
        $factory = $this->createMock(QliroOrderShippingMethodInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn(): ShippingMethod => new ShippingMethod());

        // Twenty five percent on top of the rate price, the way a Swedish store taxes delivery
        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('getShippingPrice')
            ->willReturnCallback(static fn($price, $inclTax): float => $inclTax ? $price * 1.25 : (float)$price);

        $qliroHelper = $this->createMock(QliroHelper::class);
        $qliroHelper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        $brandResolver = $this->createMock(ShippingMethodBrandResolverInterface::class);
        $brandResolver->method('resolve')->willReturn('PostNord');

        return new ShippingMethodBuilder(
            $factory,
            $taxHelper,
            $brandResolver,
            $qliroHelper,
            $this->createMock(ManagerInterface::class)
        );
    }

    private function quote(Address $address): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getCustomerTaxClassId')->willReturn(3);

        return $quote;
    }

    /**
     * The totals of the address are magic getters, so they are added to the mock
     */
    private function address(string $selectedCode, float $storedIncVat, float $storedExVat): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod'])
            ->addMethods(['getShippingInclTax', 'getShippingAmount'])
            ->getMock();

        $address->method('getShippingMethod')->willReturn($selectedCode);
        $address->method('getShippingInclTax')->willReturn($storedIncVat);
        $address->method('getShippingAmount')->willReturn($storedExVat);

        return $address;
    }

    private function rate(string $code, float $price, array $titles = []): Rate&MockObject
    {
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCode', 'getPrice', 'getMethodTitle', 'getCarrierTitle', 'getMethodDescription'])
            ->getMock();

        $rate->method('getCode')->willReturn($code);
        $rate->method('getPrice')->willReturn($price);
        $rate->method('getMethodTitle')->willReturn(array_key_exists('methodTitle', $titles) ? $titles['methodTitle'] : 'Fixed');
        $rate->method('getCarrierTitle')->willReturn(array_key_exists('carrierTitle', $titles) ? $titles['carrierTitle'] : 'Flat Rate');
        $rate->method('getMethodDescription')->willReturn(array_key_exists('methodDescription', $titles) ? $titles['methodDescription'] : 'Delivered in 2 days');

        return $rate;
    }
}

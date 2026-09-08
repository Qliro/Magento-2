<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Product\Type\Handler;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\Type\Handler\DefaultHandler;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * @see \Qliro\QliroOne\Model\Product\Type\Handler\DefaultHandler
 */
class DefaultHandlerTest extends TestCase
{
    /**
     * The tax percent Magento calculated for the customer's address wins over anything derived.
     */
    public function testPrefersTheTaxPercentOnTheQuoteItem(): void
    {
        $line = $this->buildHandler()->getQliroOrderItem($this->buildSourceItem(5.9875, 4.79, 25.0));

        self::assertSame(25.0, $line->getVatRate());
        self::assertSame(5.99, $line->getPricePerItemIncVat());
        self::assertSame(4.79, $line->getPricePerItemExVat());
        self::assertSame(QliroOrderItemInterface::TYPE_PRODUCT, $line->getType());
    }

    /**
     * Without a tax percent the rate is the one the two prices imply, read before they are rounded:
     * 5.9875 over 4.79 is 25, the rounded 5.99 over 4.79 would read back as 25.05.
     */
    public function testDerivesTheRateFromThePricesWhenTheQuoteItemHasNone(): void
    {
        $line = $this->buildHandler()->getQliroOrderItem($this->buildSourceItem(5.9875, 4.79, null));

        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * A tax exempt customer gets tax_percent 0 from Magento and equal prices. That 0 is a
     * statement and goes out as it stands, the store rate would put 25 on amounts holding no VAT.
     */
    public function testSendsAnExplicitZeroTaxPercentAsItStands(): void
    {
        $line = $this->buildHandler(25.0)->getQliroOrderItem($this->buildSourceItem(100.0, 100.0, 0.0));

        self::assertSame(0.0, $line->getVatRate());
    }

    /**
     * Without a tax percent, equal prices still say the line holds no VAT.
     */
    public function testStatesNoVatWhenThePricesHoldNoneAndTheQuoteItemHasNoTaxPercent(): void
    {
        $line = $this->buildHandler(25.0)->getQliroOrderItem($this->buildSourceItem(50.0, 50.0, null));

        self::assertSame(0.0, $line->getVatRate());
    }

    /**
     * A line with no price at all says nothing about itself, so the store calculation decides.
     */
    public function testFallsBackToTheStoreRateWhenThereIsNoPriceToReadARateFrom(): void
    {
        $line = $this->buildHandler(12.0)->getQliroOrderItem($this->buildSourceItem(0.0, 0.0, null));

        self::assertSame(12.0, $line->getVatRate());
    }

    private function buildHandler(float $storeVatRate = 0.0): DefaultHandler
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $qliroHelper = $this->createMock(QliroHelper::class);
        $qliroHelper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        $config = $this->createMock(Config::class);
        $config->method('isIngridEnabled')->willReturn(false);

        $vatRate = $this->createMock(VatRate::class);
        $vatRate->method('getVatRateForProduct')->willReturn($storeVatRate);

        return new DefaultHandler($itemFactory, $qliroHelper, $config, $vatRate, new LineVatRate());
    }

    private function buildSourceItem(float $priceInclTax, float $priceExclTax, ?float $taxPercent): TypeSourceItemInterface
    {
        $quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->addMethods(['getTaxPercent'])
            ->getMock();
        $quoteItem->method('getTaxPercent')->willReturn($taxPercent);

        $product = $this->createMock(Product::class);
        $product->method('getStoreId')->willReturn(1);

        $item = $this->createMock(TypeSourceItemInterface::class);
        $item->method('getId')->willReturn(7);
        $item->method('getSku')->willReturn('sku-1');
        $item->method('getName')->willReturn('Product');
        $item->method('getQty')->willReturn(1.0);
        $item->method('getPriceInclTax')->willReturn($priceInclTax);
        $item->method('getPriceExclTax')->willReturn($priceExclTax);
        $item->method('getParent')->willReturn(null);
        $item->method('getItem')->willReturn($quoteItem);
        $item->method('getProduct')->willReturn($product);
        $item->method('getSubscription')->willReturn(false);

        return $item;
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Product\Type\Handler;

use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\StockAvailabilityInterface;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\Type\Handler\DefaultHandler;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineReference;
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

    /**
     * Qliro identifies a line by its merchant reference: it merges two lines carrying the same one
     * and sums their quantity, so the sku alone cannot stand for a line (PLIN-408).
     */
    public function testTheLineReferenceCarriesTheCartItemId(): void
    {
        $line = $this->buildHandler()->getQliroOrderItem($this->buildSourceItem(5.99, 4.79, 25.0));

        self::assertSame('7:sku-1', $line->getMerchantReference());
    }

    /**
     * The cart shape that blocked the checkout: one sku on two lines, which reached Qliro as one
     * line of the summed quantity and then failed the validate callback's own comparison.
     */
    public function testTwoLinesOfTheSameSkuGetTheirOwnReference(): void
    {
        $handler = $this->buildHandler();

        $first = $handler->getQliroOrderItem($this->buildSourceItem(14.25, 11.4, 25.0, itemId: 518, sku: 'Kanalplast'));
        $second = $handler->getQliroOrderItem($this->buildSourceItem(14.25, 11.4, 25.0, itemId: 519, sku: 'Kanalplast'));

        self::assertSame('518:Kanalplast', $first->getMerchantReference());
        self::assertSame('519:Kanalplast', $second->getMerchantReference());
        self::assertNotSame($first->getMerchantReference(), $second->getMerchantReference());
    }

    /**
     * The metadata key the module resolves a line back to a cart item by keeps its format.
     */
    public function testTheMetadataStillNamesTheCartItem(): void
    {
        $line = $this->buildHandler()->getQliroOrderItem($this->buildSourceItem(5.99, 4.79, 25.0));

        self::assertSame(['7:sku-1' => '7:sku-1'], $line->getMetadata()['quoteItems']);
    }

    /**
     * Ingrid is told the line is out of stock only when the store cannot sell the quantity in the
     * cart, read from the same place the validate callback reads it.
     */
    public function testTellsIngridALineIsOutOfStockWhenTheQuantityIsNotSalable(): void
    {
        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->expects(self::once())
            ->method('areSalable')
            ->with(['sku-1' => ['qty' => 2.0, 'type' => 'simple']], 5)
            ->willReturn(['sku-1' => false]);

        $meta = $this->buildHandler(0.0, true, $stockAvailability)
            ->prepareMetaData($this->buildSourceItem(50.0, 40.0, 25.0, 2.0));

        self::assertTrue($meta['Ingrid']['OutOfStock']);
    }

    /**
     * A salable line is not flagged, whatever the legacy stock row of a composite parent says.
     */
    public function testDoesNotFlagASalableLineForIngrid(): void
    {
        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->method('areSalable')->willReturn(['sku-1' => true]);

        $meta = $this->buildHandler(0.0, true, $stockAvailability)
            ->prepareMetaData($this->buildSourceItem(50.0, 40.0, 25.0));

        self::assertFalse($meta['Ingrid']['OutOfStock']);
    }

    /**
     * An order line is built with its product loaded outside any store, which resolves to the admin
     * website. That is no sales channel, and the line was sold when the order was placed, so the
     * inventory is not asked and no failure is logged for asking it.
     */
    public function testDoesNotReadStockForALineWithNoSalesWebsite(): void
    {
        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->expects(self::never())->method('isSalable');
        $stockAvailability->expects(self::never())->method('areSalable');

        $item = $this->buildSourceItem(50.0, 40.0, 25.0, 1.0, 0, $this->createMock(OrderItem::class));

        $meta = $this->buildHandler(0.0, true, $stockAvailability)->prepareMetaData($item);

        self::assertFalse($meta['Ingrid']['OutOfStock']);
    }

    /**
     * Without Ingrid the flag is never sent, so no store pays for a stock lookup it does not use.
     */
    public function testDoesNotReadStockWithoutIngrid(): void
    {
        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->expects(self::never())->method('isSalable');
        $stockAvailability->expects(self::never())->method('areSalable');

        $meta = $this->buildHandler(0.0, false, $stockAvailability)
            ->prepareMetaData($this->buildSourceItem(50.0, 40.0, 25.0));

        self::assertArrayNotHasKey('Ingrid', $meta);
    }

    private function buildHandler(
        float $storeVatRate = 0.0,
        bool $ingridEnabled = false,
        ?StockAvailabilityInterface $stockAvailability = null
    ): DefaultHandler {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $qliroHelper = $this->createMock(QliroHelper::class);
        $qliroHelper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        $config = $this->createMock(Config::class);
        $config->method('isIngridEnabled')->willReturn($ingridEnabled);

        $vatRate = $this->createMock(VatRate::class);
        $vatRate->method('getVatRateForProduct')->willReturn($storeVatRate);

        return new DefaultHandler(
            $itemFactory,
            $qliroHelper,
            $config,
            $vatRate,
            new LineVatRate(),
            new LineReference(),
            $stockAvailability
        );
    }

    private function buildSourceItem(
        float $priceInclTax,
        float $priceExclTax,
        ?float $taxPercent,
        float $qty = 1.0,
        int $productWebsiteId = 5,
        ?object $sourceItem = null,
        int $itemId = 7,
        string $sku = 'sku-1'
    ): TypeSourceItemInterface {
        $quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTotalQty', 'getQuote', 'getSku', 'getProductType'])
            ->addMethods(['getTaxPercent', 'getDiscountAmount'])
            ->getMock();
        $quoteItem->method('getTaxPercent')->willReturn($taxPercent);
        $quoteItem->method('getTotalQty')->willReturn($qty);
        $quoteItem->method('getSku')->willReturn('sku-1');
        $quoteItem->method('getProductType')->willReturn('simple');
        $quoteItem->method('getDiscountAmount')->willReturn(0.0);

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(5);

        $quote = $this->createMock(Quote::class);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getAllItems')->willReturn([$quoteItem]);
        $quoteItem->method('getQuote')->willReturn($quote);

        $productStore = $this->createMock(Store::class);
        $productStore->method('getWebsiteId')->willReturn($productWebsiteId);

        $product = $this->createMock(Product::class);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getStore')->willReturn($productStore);
        $product->method('getSku')->willReturn('sku-1');
        $product->method('getWeight')->willReturn(1.0);

        $item = $this->createMock(TypeSourceItemInterface::class);
        $item->method('getId')->willReturn($itemId);
        $item->method('getSku')->willReturn($sku);
        $item->method('getType')->willReturn('simple');
        $item->method('getName')->willReturn('Product');
        $item->method('getQty')->willReturn($qty);
        $item->method('getPriceInclTax')->willReturn($priceInclTax);
        $item->method('getPriceExclTax')->willReturn($priceExclTax);
        $item->method('getParent')->willReturn(null);
        $item->method('getItem')->willReturn($sourceItem ?? $quoteItem);
        $item->method('getProduct')->willReturn($product);
        $item->method('getSubscription')->willReturn(false);

        return $item;
    }
}

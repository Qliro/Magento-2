<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\CustomerManagement;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\SubmitQuoteValidator;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Notification\ValidateOrderResponse;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;

/**
 * The validate callback tells the two sides apart by the merchant reference of a line, so a
 * reference standing for more than one line is not something it can compare (PLIN-408).
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder
 */
class ValidateOrderBuilderReferenceTest extends TestCase
{
    /**
     * One sku on two lines, each with its own reference, is the shape the module now sends and it
     * has to pass: this is the cart that was declined for every customer who built one.
     */
    public function testACartHoldingOneSkuOnTwoLinesIsAccepted(): void
    {
        $lines = [
            $this->buildLine('518:Kanalplast', 25.0, 14.25, 11.4),
            $this->buildLine('519:Kanalplast', 25.0, 14.25, 11.4),
        ];

        $response = $this->buildBuilder($lines, $lines)->create();

        self::assertNull($response->getDeclineReason());
    }

    /**
     * Two lines sharing a reference cannot be compared: whichever one the index kept, the amounts
     * of the other went unchecked, and accepting those is what this callback exists to prevent.
     */
    public function testACartWithARepeatedReferenceIsDeclined(): void
    {
        $lines = [
            $this->buildLine('Kanalplast', 25.0, 14.25, 11.4),
            $this->buildLine('Kanalplast', 25.0, 14.25, 11.4),
        ];

        $response = $this->buildBuilder($lines, $lines)->create();

        self::assertSame(ValidateOrderResponseInterface::REASON_OTHER, $response->getDeclineReason());
    }

    /**
     * The same on Qliro's side of the comparison: this is the merged line the store was declined
     * against, one line of 50 where the cart holds two of 25.
     */
    public function testAMergedQliroLineIsDeclined(): void
    {
        $quoteLines = [
            $this->buildLine('Kanalplast', 25.0, 14.25, 11.4),
        ];
        $qliroLines = [
            $this->buildLine('Kanalplast', 25.0, 14.25, 11.4),
            $this->buildLine('Kanalplast', 25.0, 14.25, 11.4),
        ];

        $response = $this->buildBuilder($quoteLines, $qliroLines)->create();

        self::assertSame(ValidateOrderResponseInterface::REASON_OTHER, $response->getDeclineReason());
    }

    /**
     * A quantity that really does disagree is still declined, the guard does not swallow it.
     */
    public function testADisagreeingQuantityIsStillDeclined(): void
    {
        $response = $this->buildBuilder(
            [$this->buildLine('518:Kanalplast', 25.0, 14.25, 11.4)],
            [$this->buildLine('518:Kanalplast', 50.0, 14.25, 11.4)]
        )->create();

        self::assertSame(ValidateOrderResponseInterface::REASON_OTHER, $response->getDeclineReason());
    }

    /**
     * @param QliroOrderItemInterface[] $quoteLines
     * @param QliroOrderItemInterface[] $qliroLines
     * @return ValidateOrderBuilder
     */
    private function buildBuilder(array $quoteLines, array $qliroLines): ValidateOrderBuilder
    {
        $responseFactory = $this->createMock(ValidateOrderResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturnCallback(static fn(): ValidateOrderResponse => new ValidateOrderResponse());

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        $orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $orderItemsBuilder->method('setQuote')->willReturnSelf();
        $orderItemsBuilder->method('create')->willReturn($quoteLines);

        $config = $this->createMock(Config::class);
        $config->method('isIngridEnabled')->willReturn(false);

        $builder = new ValidateOrderBuilder(
            $responseFactory,
            $stockRegistry,
            $orderItemsBuilder,
            $this->createMock(LogManager::class),
            $this->createMock(SubmitQuoteValidator::class),
            $this->createMock(CustomerManagement::class),
            $config
        );

        return $builder
            ->setQuote($this->buildQuote())
            ->setValidationRequest($this->buildValidationRequest($qliroLines));
    }

    private function buildQuote(): Quote
    {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(108);
        $product->method('getStore')->willReturn($store);

        $quoteItem = $this->createMock(QuoteItem::class);
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getSku')->willReturn('Kanalplast');

        $address = $this->createMock(Address::class);
        $address->method('getShippingMethod')->willReturn('flatrate_flatrate');

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(1477);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getAllVisibleItems')->willReturn([$quoteItem]);

        return $quote;
    }

    /**
     * @param QliroOrderItemInterface[] $qliroLines
     * @return ValidateOrderNotificationInterface
     */
    private function buildValidationRequest(array $qliroLines): ValidateOrderNotificationInterface
    {
        $request = $this->createMock(ValidateOrderNotificationInterface::class);
        $request->method('getSelectedShippingMethod')->willReturn('flatrate_flatrate');
        $request->method('getOrderItems')->willReturn($qliroLines);

        return $request;
    }

    private function buildLine(string $reference, float $qty, float $incVat, float $exVat): QliroOrderItemInterface
    {
        $line = new Item();
        $line->setMerchantReference($reference);
        $line->setType(QliroOrderItemInterface::TYPE_PRODUCT);
        $line->setQuantity($qty);
        $line->setPricePerItemIncVat($incVat);
        $line->setPricePerItemExVat($exVat);

        return $line;
    }
}

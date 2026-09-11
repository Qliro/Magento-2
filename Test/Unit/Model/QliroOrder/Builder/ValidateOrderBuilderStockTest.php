<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Quote\Model\CustomerManagement;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\SubmitQuoteValidator;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterfaceFactory;
use Qliro\QliroOne\Api\StockAvailabilityInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Notification\ValidateOrderResponse;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder;
use Qliro\QliroOne\Model\Stock\QuoteLines;

/**
 * The stock half of the validate callback.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder
 */
class ValidateOrderBuilderStockTest extends TestCase
{
    /**
     * @var array<string, array{qty: float, type: string}>
     */
    private array $asked = [];

    /**
     * @var int|null
     */
    private ?int $askedWebsiteId = null;

    /**
     * A line the store cannot sell in the quantity asked for declines the order for stock.
     */
    public function testDeclinesForStockWhenALineIsNotSalable(): void
    {
        $response = $this->validate(
            [$this->buildQuoteItem('sku-1', 1.0)],
            ['sku-1' => false]
        );

        self::assertSame(ValidateOrderResponseInterface::REASON_OUT_OF_STOCK, $response->getDeclineReason());
    }

    /**
     * A salable cart gets past the stock check. It stops at the next one, the shipping method,
     * which is what says the stock check let it through.
     */
    public function testDoesNotDeclineForStockWhenEveryLineIsSalable(): void
    {
        $response = $this->validate(
            [$this->buildQuoteItem('sku-1', 2.0)],
            ['sku-1' => true]
        );

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
    }

    /**
     * The quantity in the cart is part of the question, the flag alone never was.
     */
    public function testAsksAboutTheQuantityInTheCart(): void
    {
        $this->validate([$this->buildQuoteItem('sku-1', 3.0)], ['sku-1' => true]);

        self::assertSame(['sku-1' => ['qty' => 3.0, 'type' => 'simple']], $this->asked);
    }

    /**
     * The stock of the quote's own website decides, the way Magento decides it when it takes the
     * stock for the order.
     */
    public function testAsksAboutTheWebsiteTheQuoteBelongsTo(): void
    {
        $this->validate([$this->buildQuoteItem('sku-1', 1.0)], ['sku-1' => true]);

        self::assertSame(4, $this->askedWebsiteId);
    }

    /**
     * A cart that has already become an order has taken its own stock. An inventory that counts
     * reservations counts that against it, so a callback sent again after the order was placed
     * would be refused on the stock the order itself is holding.
     */
    public function testDoesNotAskAboutACartThatHasAlreadyBecomeAnOrder(): void
    {
        $response = $this->validate(
            [$this->buildQuoteItem('sku-1', 1.0)],
            ['sku-1' => false],
            false
        );

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
        self::assertSame([], $this->asked);
    }

    /**
     * @param QuoteItem[] $quoteItems
     * @param array<string, bool> $salable
     * @param bool $isActive
     * @return ValidateOrderResponseInterface
     */
    private function validate(array $quoteItems, array $salable, bool $isActive = true): ValidateOrderResponseInterface
    {
        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->method('areSalable')->willReturnCallback(
            function (array $lines, int $websiteId) use ($salable): array {
                $this->asked = $lines;
                $this->askedWebsiteId = $websiteId;

                $result = [];

                foreach ($lines as $sku => $line) {
                    $result[$sku] = $salable[$sku] ?? true;
                }

                return $result;
            }
        );

        $responseFactory = $this->createMock(ValidateOrderResponseInterfaceFactory::class);
        $responseFactory->method('create')->willReturnCallback(
            static fn(): ValidateOrderResponseInterface => new ValidateOrderResponse()
        );

        $builder = new ValidateOrderBuilder(
            $responseFactory,
            $stockAvailability,
            new QuoteLines(),
            $this->createMock(OrderItemsBuilder::class),
            $this->createMock(LogManager::class),
            $this->createMock(SubmitQuoteValidator::class),
            $this->createMock(CustomerManagement::class),
            $this->createMock(Config::class)
        );

        $builder->setQuote($this->buildQuote($quoteItems, $isActive));
        $builder->setValidationRequest($this->createMock(ValidateOrderNotificationInterface::class));

        return $builder->create();
    }

    /**
     * @param QuoteItem[] $quoteItems
     * @return Quote
     */
    private function buildQuote(array $quoteItems, bool $isActive = true): Quote
    {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(4);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(11);
        $quote->method('getAllItems')->willReturn($quoteItems);
        $quote->method('getStore')->willReturn($store);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getIsActive')->willReturn($isActive);

        return $quote;
    }

    private function buildQuoteItem(
        string $sku,
        float $qty,
        ?float $parentQty = null,
        array $children = [],
        string $productType = 'simple'
    ): QuoteItem {
        $item = $this->createMock(QuoteItem::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getTotalQty')->willReturn($parentQty === null ? $qty : $qty * $parentQty);
        $item->method('getChildren')->willReturn($children);
        $item->method('getProductType')->willReturn($productType);

        return $item;
    }
}

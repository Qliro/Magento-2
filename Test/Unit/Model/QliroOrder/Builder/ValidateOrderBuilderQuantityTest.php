<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Quote\Model\CustomerManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\App\Emulation as StoreEmulation;
use Magento\Store\Model\StoreManagerInterface;
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
use Qliro\QliroOne\Model\QliroOrder\LineQuantity;
use Qliro\QliroOne\Model\Quote\WholeQuantityValidator;
use Qliro\QliroOne\Model\Stock\QuoteLines;

/**
 * The quantity half of the validate callback. The cart is refused where the Qliro order is created,
 * so what reaches here is a cart that turned fractional after that: placing it would charge a
 * quantity Qliro was never told about. Both readings come from `WholeQuantityValidator`.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder
 */
class ValidateOrderBuilderQuantityTest extends TestCase
{
    /**
     * @var array<int, array<string, mixed>> Every line the log was given
     */
    private array $logged = [];

    /**
     * A cart holding half a metre is declined rather than placed at a quantity of the module's
     * own invention.
     */
    public function testDeclinesACartHoldingAPartOfAnItem(): void
    {
        $response = $this->validate([$this->quoteItem('CABLE-5MM', 0.5)]);

        self::assertSame(ValidateOrderResponseInterface::REASON_OTHER, $response->getDeclineReason());
    }

    /**
     * A whole cart gets past this check. It stops at the next one, the shipping method, which is
     * what says the quantity check let it through.
     */
    public function testDoesNotDeclineACartOfWholeQuantities(): void
    {
        $response = $this->validate([$this->quoteItem('SKU-1', 3.0)]);

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
    }

    /**
     * A cart of three that reaches the check as 2.9999999999999996 is a cart of three. Refusing
     * it would be a checkout nobody can finish.
     */
    public function testDoesNotDeclineAWholeQuantityThatArrivedAsAFloat(): void
    {
        $response = $this->validate([$this->quoteItem('SKU-1', 2.9999999999999996)]);

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
    }

    /**
     * The merchant has a declined order to explain, so the log names the line and its quantity.
     */
    public function testLogsTheLineItDeclinedFor(): void
    {
        $this->validate([$this->quoteItem('SKU-1', 1.0), $this->quoteItem('CABLE-5MM', 1.5)]);

        self::assertSame([['sku' => 'CABLE-5MM', 'qty' => 1.5]], $this->logged);
    }

    /**
     * Every line the buyer has to change, not the first one: the same reading the refusal at the
     * checkout names them all by, so the merchant and the buyer are told the same thing.
     */
    public function testLogsEveryLineItDeclinedFor(): void
    {
        $this->validate([$this->quoteItem('CABLE-5MM', 0.5), $this->quoteItem('ROPE-8MM', 2.25)]);

        self::assertSame(
            [['sku' => 'CABLE-5MM', 'qty' => 0.5], ['sku' => 'ROPE-8MM', 'qty' => 2.25]],
            $this->logged
        );
    }

    /**
     * @param QuoteItem[] $quoteItems
     * @return ValidateOrderResponseInterface
     */
    private function validate(array $quoteItems): ValidateOrderResponseInterface
    {
        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->method('areSalable')->willReturnCallback(
            static fn(array $lines): array => array_fill_keys(array_keys($lines), true)
        );

        $responseFactory = $this->createMock(ValidateOrderResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturnCallback(static fn(): ValidateOrderResponse => new ValidateOrderResponse());

        $builder = new ValidateOrderBuilder(
            $responseFactory,
            $stockAvailability,
            new QuoteLines(),
            $this->createMock(OrderItemsBuilder::class),
            $this->logManager(),
            $this->createMock(SubmitQuoteValidator::class),
            $this->createMock(CustomerManagement::class),
            $this->createMock(Config::class),
            new WholeQuantityValidator(new LineQuantity()),
            // The last three are optional on the constructor and fall back to the object manager,
            // which a unit test has none of, so they are handed over here
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StoreEmulation::class)
        );

        $builder->setQuote($this->quote($quoteItems));
        $builder->setValidationRequest($this->createMock(ValidateOrderNotificationInterface::class));

        return $builder->create();
    }

    /**
     * A log that keeps what the decline was told about the line
     */
    private function logManager(): LogManager
    {
        $logManager = $this->createMock(LogManager::class);
        $logManager->method('debug')->willReturnCallback(
            function ($message, array $context = []) : void {
                $details = $context['extra']['details'] ?? [];

                if (!is_array($details)) {
                    return;
                }

                foreach ($details as $line) {
                    if (is_array($line) && isset($line['sku'], $line['qty'])) {
                        $this->logged[] = ['sku' => $line['sku'], 'qty' => $line['qty']];
                    }
                }
            }
        );

        return $logManager;
    }

    /**
     * @param QuoteItem[] $quoteItems
     */
    private function quote(array $quoteItems): Quote
    {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(4);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(11);
        $quote->method('getAllItems')->willReturn($quoteItems);
        $quote->method('getStore')->willReturn($store);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getIsActive')->willReturn(true);

        return $quote;
    }

    private function quoteItem(string $sku, float $qty): QuoteItem
    {
        $item = $this->createMock(QuoteItem::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getTotalQty')->willReturn($qty);
        $item->method('getChildren')->willReturn([]);
        $item->method('getProductType')->willReturn('simple');

        return $item;
    }
}

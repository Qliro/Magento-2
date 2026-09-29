<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\CustomerManagement;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\SubmitQuoteValidator;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterfaceFactory;
use Qliro\QliroOne\Api\StockAvailabilityInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Notification\ValidateOrderResponse;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineQuantity;
use Qliro\QliroOne\Model\Quote\WholeQuantityValidator;
use Qliro\QliroOne\Model\Stock\QuoteLines;

/**
 * The validate callback puts the quote on the delivery Qliro states (PLIN-461)
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder
 */
class ValidateOrderBuilderShippingTest extends TestCase
{
    private const FREE = 'freeshipping_freeshipping';
    private const SCHOOL = 'school_authority_company_shipping_school';

    /**
     * What each rate costs once the quote is on it, VAT included
     */
    private const QUOTE_PRICES = [self::FREE => 0.0, self::SCHOOL => 99.0];

    private string $quoteMethod = '';
    private float $quoteShippingInclTax = 0.0;
    private int $totalsCollected = 0;

    /**
     * The shape of order 000185373: the quote on the school delivery, Qliro on free shipping.
     * The quote is brought to Qliro's choice and the order is accepted.
     */
    public function testPutsTheQuoteOnTheMethodQliroSelected(): void
    {
        $response = $this->validate(self::SCHOOL, self::FREE, 0.0);

        self::assertNull($response->getDeclineReason());
        self::assertSame(self::FREE, $this->quoteMethod);
        self::assertSame(1, $this->totalsCollected);
    }

    /**
     * A code the quote has no rate for is accepted as before, on the quote's own method
     */
    public function testKeepsTheQuoteMethodWhenQliroCodeIsNotARate(): void
    {
        $response = $this->validate(self::SCHOOL, 'unknown_carrier', 0.0);

        self::assertNull($response->getDeclineReason());
        self::assertSame(self::SCHOOL, $this->quoteMethod);
    }

    /**
     * The buyer pays Qliro's total, so a store that prices the same delivery differently would
     * place an order for another amount than the reservation
     */
    public function testDeclinesWhenTheStorePricesTheMethodDifferently(): void
    {
        $response = $this->validate(self::FREE, self::SCHOOL, 49.0);

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
    }

    /**
     * An öre apart is rounding, not a different price
     */
    public function testAcceptsAPriceAnOreApart(): void
    {
        $response = $this->validate(self::FREE, self::SCHOOL, 98.99);

        self::assertNull($response->getDeclineReason());
        self::assertSame(self::SCHOOL, $this->quoteMethod);
    }

    /**
     * A quote already on Qliro's choice is left as it is and its totals are not collected again
     */
    public function testLeavesAQuoteAlreadyOnTheSelectedMethod(): void
    {
        $response = $this->validate(self::FREE, self::FREE, 0.0);

        self::assertNull($response->getDeclineReason());
        self::assertSame(0, $this->totalsCollected);
    }

    /**
     * Unifaun keeps its own code on the quote, which Qliro's selection is never equal to
     */
    public function testLeavesAUnifaunQuoteAlone(): void
    {
        $response = $this->validate('qliroone_unifaun', 'PNL:1234', 49.0, true);

        self::assertNull($response->getDeclineReason());
        self::assertSame('qliroone_unifaun', $this->quoteMethod);
    }

    private function validate(
        string $quoteMethod,
        string $selectedMethod,
        float $qliroShippingPrice,
        bool $isUnifaun = false
    ): ValidateOrderResponseInterface {
        $this->quoteMethod = $quoteMethod;
        $this->quoteShippingInclTax = self::QUOTE_PRICES[$quoteMethod] ?? 0.0;

        $product = $this->productLine();

        $responseFactory = $this->createMock(ValidateOrderResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturnCallback(static fn(): ValidateOrderResponse => new ValidateOrderResponse());

        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->method('areSalable')->willReturnCallback(
            static fn(array $lines): array => array_fill_keys(array_keys($lines), true)
        );

        $orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $orderItemsBuilder->method('setQuote')->willReturnSelf();
        $orderItemsBuilder->method('create')->willReturn([$product]);

        $config = $this->createMock(Config::class);
        $config->method('isUnifaunEnabled')->willReturn($isUnifaun);
        $config->method('isIngridEnabled')->willReturn(false);

        $request = $this->createMock(ValidateOrderNotificationInterface::class);
        $request->method('getSelectedShippingMethod')->willReturn($selectedMethod);
        $request->method('getOrderItems')->willReturn([$product, $this->shippingLine($qliroShippingPrice)]);

        $builder = new ValidateOrderBuilder(
            $responseFactory,
            $stockAvailability,
            new QuoteLines(),
            $orderItemsBuilder,
            $this->createMock(LogManager::class),
            $this->createMock(SubmitQuoteValidator::class),
            $this->createMock(CustomerManagement::class),
            $config,
            new WholeQuantityValidator(new LineQuantity())
        );

        return $builder->setQuote($this->quote())->setValidationRequest($request)->create();
    }

    private function quote(): Quote
    {
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(108);
        $product->method('getStore')->willReturn($store);

        $quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getSku', 'getTotalQty', 'getProductType', 'getChildren'])
            ->getMock();
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getSku')->willReturn('BOOK-1');
        $quoteItem->method('getTotalQty')->willReturn(1.0);
        $quoteItem->method('getProductType')->willReturn('simple');
        $quoteItem->method('getChildren')->willReturn([]);

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod', 'getShippingRateByCode'])
            ->addMethods(['setShippingMethod', 'getShippingInclTax'])
            ->getMock();
        $address->method('getShippingMethod')->willReturnCallback(fn() => $this->quoteMethod);
        $address->method('setShippingMethod')->willReturnCallback(function ($code) use ($address) {
            $this->quoteMethod = $code;

            return $address;
        });
        $address->method('getShippingRateByCode')->willReturnCallback(
            fn($code) => isset(self::QUOTE_PRICES[$code]) ? $this->createMock(Rate::class) : false
        );
        $address->method('getShippingInclTax')->willReturnCallback(fn() => $this->quoteShippingInclTax);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(185373);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getAllVisibleItems')->willReturn([$quoteItem]);
        $quote->method('getAllItems')->willReturn([$quoteItem]);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getStore')->willReturn($store);
        $quote->method('collectTotals')->willReturnCallback(function () use ($quote) {
            $this->totalsCollected++;
            $this->quoteShippingInclTax = self::QUOTE_PRICES[$this->quoteMethod] ?? 0.0;

            return $quote;
        });

        return $quote;
    }

    private function productLine(): QliroOrderItemInterface
    {
        $line = new Item();
        $line->setMerchantReference('BOOK-1');
        $line->setType(QliroOrderItemInterface::TYPE_PRODUCT);
        $line->setQuantity(1);
        $line->setPricePerItemIncVat(302.98);
        $line->setPricePerItemExVat(285.83);

        return $line;
    }

    private function shippingLine(float $incVat): QliroOrderItemInterface
    {
        $line = new Item();
        $line->setMerchantReference('shipping');
        $line->setType(QliroOrderItemInterface::TYPE_SHIPPING);
        $line->setQuantity(1);
        $line->setPricePerItemIncVat($incVat);
        $line->setPricePerItemExVat($incVat / 1.25);

        return $line;
    }
}

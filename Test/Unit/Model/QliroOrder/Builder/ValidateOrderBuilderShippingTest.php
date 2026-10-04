<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Catalog\Model\Product;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\CustomerManagement;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\SubmitQuoteValidator;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
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
    private int $quotesSaved = 0;
    private string $assignmentMethod = '';
    private bool $isActive = true;
    private bool $saveFails = false;
    private int $currentStoreId = 1;
    private int $quoteStoreId = 1;
    private ?int $collectedInStoreId = null;
    private int $emulationsOpen = 0;

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
        self::assertSame(1, $this->quotesSaved);
        self::assertSame(self::FREE, $this->assignmentMethod);
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

        self::assertSame(ValidateOrderResponseInterface::REASON_OTHER, $response->getDeclineReason());
        self::assertSame(0, $this->quotesSaved);
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
     * Without a shipping line Qliro charges nothing for delivery, so a paid one is declined
     */
    public function testDeclinesAPaidMethodWhenQliroSendsNoShippingLine(): void
    {
        $response = $this->validate(self::FREE, self::SCHOOL, null);

        self::assertSame(ValidateOrderResponseInterface::REASON_OTHER, $response->getDeclineReason());
    }

    /**
     * A callback sent again after the order was placed does not touch the quote the order came from
     */
    public function testLeavesAQuoteThatIsAlreadyAnOrder(): void
    {
        $this->isActive = false;

        $this->validate(self::SCHOOL, self::FREE, 0.0);

        self::assertSame(self::SCHOOL, $this->quoteMethod);
        self::assertSame(0, $this->quotesSaved);
    }

    /**
     * A quote that cannot be saved still gets its payment, placing applies the Qliro line again
     */
    public function testAcceptsWhenTheQuoteCannotBeSaved(): void
    {
        $this->saveFails = true;

        $response = $this->validate(self::SCHOOL, self::FREE, 0.0);

        self::assertNull($response->getDeclineReason());
    }

    /**
     * The callback runs in the default store view, so a quote from another one is priced in its own
     */
    public function testCollectsTheTotalsInTheQuoteStoreView(): void
    {
        $this->quoteStoreId = 3;

        $response = $this->validate(self::SCHOOL, self::FREE, 0.0);

        self::assertNull($response->getDeclineReason());
        self::assertSame(3, $this->collectedInStoreId);
        self::assertSame(0, $this->emulationsOpen);
        self::assertSame(1, $this->currentStoreId);
    }

    /**
     * A price decline leaves the emulation too
     */
    public function testLeavesTheQuoteStoreViewOnADecline(): void
    {
        $this->quoteStoreId = 3;

        $this->validate(self::FREE, self::SCHOOL, 49.0);

        self::assertSame(0, $this->emulationsOpen);
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
     * Unifaun and Ingrid keep a fixed code of their own on the quote, whatever Qliro selects
     */
    public function testLeavesAUnifaunQuoteAlone(): void
    {
        $response = $this->validate(self::FREE, self::SCHOOL, 49.0, 'unifaun');

        self::assertNull($response->getDeclineReason());
        self::assertSame(self::FREE, $this->quoteMethod);
    }

    public function testLeavesAnIngridQuoteAlone(): void
    {
        $response = $this->validate(self::FREE, self::SCHOOL, 49.0, 'ingrid');

        self::assertNull($response->getDeclineReason());
        self::assertSame(self::FREE, $this->quoteMethod);
    }

    private function validate(
        string $quoteMethod,
        string $selectedMethod,
        ?float $qliroShippingPrice,
        string $integration = ''
    ): ValidateOrderResponseInterface {
        $this->quoteMethod = $quoteMethod;
        $this->assignmentMethod = $quoteMethod;
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
        $config->method('isUnifaunEnabled')->willReturn($integration === 'unifaun');
        $config->method('isIngridEnabled')->willReturn($integration === 'ingrid');

        $request = $this->createMock(ValidateOrderNotificationInterface::class);
        $request->method('getSelectedShippingMethod')->willReturn($selectedMethod);
        $request->method('getOrderItems')->willReturn(
            $qliroShippingPrice === null ? [$product] : [$product, $this->shippingLine($qliroShippingPrice)]
        );

        $builder = new ValidateOrderBuilder(
            $responseFactory,
            $stockAvailability,
            new QuoteLines(),
            $orderItemsBuilder,
            $this->createMock(LogManager::class),
            $this->createMock(SubmitQuoteValidator::class),
            $this->createMock(CustomerManagement::class),
            $config,
            new WholeQuantityValidator(new LineQuantity()),
            $this->quoteRepository(),
            $this->storeManager(),
            $this->storeEmulation()
        );

        return $builder->setQuote($this->quote())->setValidationRequest($request)->create();
    }

    private function storeManager(): StoreManagerInterface
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function () {
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn($this->currentStoreId);

            return $store;
        });

        return $storeManager;
    }

    private function storeEmulation(): Emulation
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(function ($storeId): void {
            $this->currentStoreId = (int)$storeId;
            $this->emulationsOpen++;
        });
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () use ($emulation) {
            $this->currentStoreId = 1;
            $this->emulationsOpen--;

            return $emulation;
        });

        return $emulation;
    }

    private function quoteRepository(): CartRepositoryInterface
    {
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (): void {
            if ($this->saveFails) {
                throw new \Magento\Framework\Exception\CouldNotSaveException(__('The address failed to save'));
            }

            $this->quotesSaved++;
        });

        return $repository;
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
            ->onlyMethods(['getShippingMethod', 'getShippingRateByCode', 'collectShippingRates', 'getAllShippingRates', 'getStreetLine'])
            ->addMethods(['setShippingMethod', 'getShippingInclTax', 'setCollectShippingRates'])
            ->getMock();
        // A code missing from the saved rates is rated again, and the carriers answer with the same set
        $address->method('collectShippingRates')->willReturnSelf();
        $address->method('setCollectShippingRates')->willReturnSelf();
        $address->method('getAllShippingRates')->willReturn([]);
        $address->method('getShippingMethod')->willReturnCallback(fn() => $this->quoteMethod);
        $address->method('setShippingMethod')->willReturnCallback(function ($code) use ($address) {
            $this->quoteMethod = $code;

            return $address;
        });
        $address->method('getShippingRateByCode')->willReturnCallback(
            fn($code) => isset(self::QUOTE_PRICES[$code]) ? $this->createMock(Rate::class) : false
        );
        $address->method('getShippingInclTax')->willReturnCallback(fn() => $this->quoteShippingInclTax);

        $shipping = $this->createMock(ShippingInterface::class);
        $shipping->method('setMethod')->willReturnCallback(function ($code) use ($shipping) {
            $this->assignmentMethod = $code;

            return $shipping;
        });
        $assignment = $this->createMock(ShippingAssignmentInterface::class);
        $assignment->method('getShipping')->willReturn($shipping);
        // CartExtensionInterface is generated by Magento and missing here
        $extension = new class([$assignment]) {
            public function __construct(private array $assignments)
            {
            }

            public function getShippingAssignments(): array
            {
                return $this->assignments;
            }
        };

        $quote = $this->createMock(Quote::class);
        $quote->method('getExtensionAttributes')->willReturn($extension);
        $quote->method('getId')->willReturn(185373);
        $quote->method('getStoreId')->willReturnCallback(fn() => $this->quoteStoreId);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getAllVisibleItems')->willReturn([$quoteItem]);
        $quote->method('getAllItems')->willReturn([$quoteItem]);
        $quote->method('getIsActive')->willReturnCallback(fn() => $this->isActive);
        $quote->method('getStore')->willReturn($store);
        $quote->method('collectTotals')->willReturnCallback(function () use ($quote) {
            $this->totalsCollected++;
            $this->collectedInStoreId = $this->currentStoreId;
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

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\CustomerManagement;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\SubmitQuoteValidator;
use Magento\Store\Model\App\Emulation as StoreEmulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
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
 * The delivery Qliro validates against is put back on a quote that lost it
 *
 * @see ValidateOrderBuilder
 */
class ValidateOrderBuilderShippingTest extends TestCase
{
    /** @var string|null The code the builder wrote to the quote, null when it wrote none */
    private ?string $appliedMethod = null;

    /** @var bool Whether the quote was saved after the code was applied */
    private bool $quoteSaved = false;

    /** Read by the address double, which cannot reach a private property */
    public function readAppliedMethod(): ?string
    {
        return $this->appliedMethod;
    }

    /** @var float What the carriers price the applied method at */
    private float $quotePrice = 0.0;

    /** Read by the address double */
    public function quoteShippingPrice(): float
    {
        return $this->quotePrice;
    }

    /** Written by the address double */
    public function recordAppliedMethod(?string $code): void
    {
        $this->appliedMethod = $code;
    }

    public function testAppliesTheMethodQliroSelectedWhenTheCarriersStillOfferIt(): void
    {
        $response = $this->validate('dhl_pickup_A', ['dhl_pickup_A', 'dhl_pickup_B']);

        self::assertNotSame(
            ValidateOrderResponseInterface::REASON_SHIPPING,
            $response->getDeclineReason(),
            'the order was declined although the selected method was available'
        );
        self::assertSame('dhl_pickup_A', $this->appliedMethod);
        self::assertTrue($this->quoteSaved, 'the applied method was not saved on the quote');
    }

    public function testDeclinesWhenTheSelectedMethodIsNoLongerOffered(): void
    {
        $response = $this->validate('dhl_pickup_A', ['dhl_pickup_B']);

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
        self::assertNull($this->appliedMethod);
        self::assertFalse($this->quoteSaved);
    }

    /**
     * The buyer pays Qliro's total, so a delivery the store prices differently is not accepted.
     */
    public function testDeclinesWhenTheStorePricesTheMethodDifferently(): void
    {
        $response = $this->validate('dhl_pickup_A', ['dhl_pickup_A'], 49.0, 0.0);

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
        self::assertNull($this->appliedMethod, 'the method was left on the quote after a mismatch');
        self::assertFalse($this->quoteSaved, 'the quote was saved although the prices disagreed');
    }

    /**
     * Qliro's price for the delivery and the store's reach the comparison through different
     * rounding, so an öre between them is ordinary. Declining on it fails an order the buyer has
     * already paid for, which is what this whole path exists to stop.
     */
    public function testAcceptsASingleOreOfRoundingBetweenTheTwoPrices(): void
    {
        $response = $this->validate('dhl_pickup_A', ['dhl_pickup_A'], 49.01, 49.00);

        self::assertNotSame(
            ValidateOrderResponseInterface::REASON_SHIPPING,
            $response->getDeclineReason(),
            'an öre of rounding declined an order the buyer had paid for'
        );
        self::assertSame('dhl_pickup_A', $this->appliedMethod);
        self::assertTrue($this->quoteSaved);
    }

    /**
     * Two öre is a difference in price rather than in rounding, and the buyer pays Qliro.
     */
    public function testStillDeclinesTwoOreOfDifference(): void
    {
        $response = $this->validate('dhl_pickup_A', ['dhl_pickup_A'], 49.02, 49.00);

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
        self::assertNull($this->appliedMethod);
    }

    public function testDeclinesWhenQliroStatesNoSelection(): void
    {
        $response = $this->validate(null, ['dhl_pickup_A']);

        self::assertSame(ValidateOrderResponseInterface::REASON_SHIPPING, $response->getDeclineReason());
        self::assertNull($this->appliedMethod);
    }

    /**
     * @param string|null $selectedMethod What Qliro states the buyer picked
     * @param string[] $offeredRates The codes the carriers return for the quote address
     * @return ValidateOrderResponseInterface
     */
    private function validate(
        ?string $selectedMethod,
        array $offeredRates,
        float $quotePrice = 0.0,
        float $qliroPrice = 0.0
    ): ValidateOrderResponseInterface {
        $this->quotePrice = $quotePrice;
        $responseFactory = $this->createMock(ValidateOrderResponseInterfaceFactory::class);
        $responseFactory->method('create')->willReturnCallback(
            static fn(): ValidateOrderResponseInterface => new ValidateOrderResponse()
        );

        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->method('areSalable')->willReturn([]);

        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('save')->willReturnCallback(function (): void {
            $this->quoteSaved = true;
        });

        $orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $orderItemsBuilder->method('setQuote')->willReturnSelf();
        $orderItemsBuilder->method('create')->willReturn([]);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(6);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $builder = new ValidateOrderBuilder(
            $responseFactory,
            $stockAvailability,
            new QuoteLines(),
            $orderItemsBuilder,
            $this->createMock(LogManager::class),
            $this->createMock(SubmitQuoteValidator::class),
            $this->createMock(CustomerManagement::class),
            $this->createMock(Config::class),
            new WholeQuantityValidator(new LineQuantity()),
            $quoteRepository,
            $storeManager,
            $this->createMock(StoreEmulation::class)
        );

        $request = $this->createMock(ValidateOrderNotificationInterface::class);
        $request->method('getSelectedShippingMethod')->willReturn($selectedMethod);
        $shippingLine = $this->createMock(\Qliro\QliroOne\Api\Data\QliroOrderItemInterface::class);
        $shippingLine->method('getType')->willReturn(\Qliro\QliroOne\Api\Data\QliroOrderItemInterface::TYPE_SHIPPING);
        $shippingLine->method('getQuantity')->willReturn(1.0);
        $shippingLine->method('getPricePerItemIncVat')->willReturn($qliroPrice);
        $request->method('getOrderItems')->willReturn([$shippingLine]);

        $builder->setQuote($this->buildQuote($offeredRates));
        $builder->setValidationRequest($request);

        return $builder->create();
    }

    /**
     * A quote with no shipping method of its own, which is the state the defect produced
     *
     * @param string[] $offeredRates
     * @return Quote
     */
    private function buildQuote(array $offeredRates): Quote
    {
        $test = $this;

        // A double rather than a mock: the address methods the builder uses are magic ones on
        // DataObject, and PHPUnit cannot configure what the class does not declare.
        $address = new class ($offeredRates, $test) {
            /** @param string[] $offeredRates */
            public function __construct(private array $offeredRates, private $test)
            {
            }

            public function setCollectShippingRates($flag): self
            {
                return $this;
            }

            public function collectShippingRates(): self
            {
                return $this;
            }

            /** @return DataObject[] */
            public function getAllShippingRates(): array
            {
                return \array_map(
                    static fn(string $code): DataObject => new DataObject(['code' => $code]),
                    $this->offeredRates
                );
            }

            public function getShippingMethod(): ?string
            {
                return $this->test->readAppliedMethod();
            }

            public function setShippingMethod($code): self
            {
                $this->test->recordAppliedMethod($code);

                return $this;
            }


            public function getStreetLine($number)
            {
                return 'Sveavagen 1';
            }

            public function getCity(): string
            {
                return 'Stockholm';
            }

            public function getPostcode(): string
            {
                return '11122';
            }

            public function getCountryId(): string
            {
                return 'SE';
            }

            public function getShippingInclTax(): float
            {
                return $this->test->quoteShippingPrice();
            }
        };

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(4);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(11);
        $quote->method('getStoreId')->willReturn(6);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getAllItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }
}

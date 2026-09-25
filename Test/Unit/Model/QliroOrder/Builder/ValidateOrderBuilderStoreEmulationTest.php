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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
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
 * The rating for the validation callback runs in the quote's own store view, and Magento allows
 * a single level of emulation: a nested start is refused silently, so a stop this method did not
 * earn would end the emulation its caller is still inside.
 *
 * @see ValidateOrderBuilder
 */
class ValidateOrderBuilderStoreEmulationTest extends TestCase
{
    private const QUOTE_STORE_ID = 4;
    private const CURRENT_STORE_ID = 6;

    /**
     * Which store the store manager answers with, which an emulation that takes effect changes.
     */
    private int $currentStoreId = self::CURRENT_STORE_ID;

    protected function setUp(): void
    {
        $this->currentStoreId = self::CURRENT_STORE_ID;
    }

    /**
     * A quote in another store view is rated there, and the emulation is ended before the
     * callback answers Qliro: nothing later in the request resets it, so one left standing
     * prices and formats everything after it in the wrong store.
     */
    public function testStopsTheEmulationItStarted(): void
    {
        $emulation = $this->emulationThatTakesEffect();
        $emulation->expects(self::once())->method('stopEnvironmentEmulation');

        $this->validate($emulation, ['dhl_pickup_A']);
    }

    /**
     * A delivery Qliro named that the carriers no longer offer returns from the middle of the
     * rating, which is the path the decline takes.
     */
    public function testStopsTheEmulationWhenTheMethodIsNoLongerOffered(): void
    {
        $emulation = $this->emulationThatTakesEffect();
        $emulation->expects(self::once())->method('stopEnvironmentEmulation');

        $this->validate($emulation, ['some_other_method']);
    }

    /**
     * Magento refuses a nested emulation silently, and the store stays the caller's. Stopping
     * then would restore the environment of whoever is still inside their own emulation.
     */
    public function testStopsNothingWhenTheStartWasRefused(): void
    {
        $emulation = $this->createMock(StoreEmulation::class);
        $emulation->expects(self::once())->method('startEnvironmentEmulation');
        $emulation->expects(self::never())->method('stopEnvironmentEmulation');

        $this->validate($emulation, ['dhl_pickup_A']);
    }

    /**
     * A store view that cannot be emulated at all leaves nothing to stop.
     */
    public function testStopsNothingWhenTheEmulationCouldNotStart(): void
    {
        $emulation = $this->createMock(StoreEmulation::class);
        $emulation->method('startEnvironmentEmulation')
            ->willThrowException(new \RuntimeException('no such store view'));
        $emulation->expects(self::never())->method('stopEnvironmentEmulation');

        $this->validate($emulation, ['dhl_pickup_A']);
    }

    /**
     * @return StoreEmulation&MockObject
     */
    private function emulationThatTakesEffect(): StoreEmulation
    {
        $emulation = $this->createMock(StoreEmulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(function (): void {
            $this->currentStoreId = self::QUOTE_STORE_ID;
        });

        return $emulation;
    }

    /**
     * @param StoreEmulation $emulation
     * @param string[] $offeredRates
     * @return void
     */
    private function validate(StoreEmulation $emulation, array $offeredRates): void
    {
        $responseFactory = $this->createMock(ValidateOrderResponseInterfaceFactory::class);
        $responseFactory->method('create')->willReturnCallback(
            static fn(): ValidateOrderResponse => new ValidateOrderResponse()
        );

        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->method('areSalable')->willReturn([]);

        $orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $orderItemsBuilder->method('setQuote')->willReturnSelf();
        $orderItemsBuilder->method('create')->willReturn([]);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturnCallback(function (): int {
            return $this->currentStoreId;
        });
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
            $this->createMock(CartRepositoryInterface::class),
            $storeManager,
            $emulation
        );

        $request = $this->createMock(ValidateOrderNotificationInterface::class);
        $request->method('getSelectedShippingMethod')->willReturn('dhl_pickup_A');
        $shippingLine = $this->createMock(QliroOrderItemInterface::class);
        $shippingLine->method('getType')->willReturn(QliroOrderItemInterface::TYPE_SHIPPING);
        $shippingLine->method('getQuantity')->willReturn(1.0);
        $shippingLine->method('getPricePerItemIncVat')->willReturn(0.0);
        $request->method('getOrderItems')->willReturn([$shippingLine]);

        $builder->setQuote($this->buildQuote($offeredRates));
        $builder->setValidationRequest($request);
        $builder->create();
    }

    /**
     * @param string[] $offeredRates
     * @return Quote
     */
    private function buildQuote(array $offeredRates): Quote
    {
        // A double rather than a mock: the address methods the builder uses are magic ones on
        // DataObject, and PHPUnit cannot configure what the class does not declare.
        $address = new class ($offeredRates) {
            /** @param string[] $offeredRates */
            public function __construct(private array $offeredRates)
            {
            }

            private ?string $method = null;

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
                return $this->method;
            }

            public function setShippingMethod($code): self
            {
                $this->method = $code;

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
                return 0.0;
            }
        };

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(11);
        $quote->method('getStoreId')->willReturn(self::QUOTE_STORE_ID);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getAllItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }
}

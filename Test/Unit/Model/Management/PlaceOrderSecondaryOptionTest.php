<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\Order\OrganizationNumber;
use Qliro\QliroOne\Model\Order\PlacedOrderFinder;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineDifferences;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * The order keeps the secondary option Qliro holds on its shipping line
 *
 * @see \Qliro\QliroOne\Model\Management\PlaceOrder::execute
 */
class PlaceOrderSecondaryOptionTest extends TestCase
{
    private const METHOD = 'flatrate_flatrate';
    private const KEY = Config::QLIROONE_ADDITIONAL_INFO_SHIPPING_SECONDARY_OPTION;

    private array $paymentInfo = [];
    private string $quoteMethod = self::METHOD;
    private string $integration = '';
    private array $debugLogs = [];

    public function testSavesTheOptionOnTheOrder(): void
    {
        $this->place($this->shippingLine(['ShippingMethodSecondaryOptionMerchantReference' => 'pickup-point-2']));

        self::assertSame('pickup-point-2', $this->paymentInfo[self::KEY]);
    }

    /**
     * Placed again after an attempt that wrote another option
     */
    public function testRemovesAnOptionTheQliroOrderNoLongerHolds(): void
    {
        $this->paymentInfo[self::KEY] = 'pickup-point-1';

        $this->place($this->shippingLine([]));

        self::assertArrayNotHasKey(self::KEY, $this->paymentInfo);
    }

    public function testKeepsTheShippingPropertiesBesideTheOption(): void
    {
        $this->place($this->shippingLine([
            'ShippingMethodSecondaryOptionMerchantReference' => 'pickup-point-2',
            'AdditionalShippingProperties' => ['door_code' => '1234'],
        ]));

        self::assertSame('pickup-point-2', $this->paymentInfo[self::KEY]);
        self::assertSame(['door_code' => '1234'], $this->paymentInfo[Config::QLIROONE_ADDITIONAL_INFO_SHIPPING_PROPERTIES]);
    }

    public function testIgnoresTheOptionForUnifaunAndIngrid(): void
    {
        foreach (['unifaun', 'ingrid'] as $integration) {
            $this->integration = $integration;
            $this->paymentInfo = [];

            $this->place($this->shippingLine(['ShippingMethodSecondaryOptionMerchantReference' => 'pickup-point-2']));

            self::assertArrayNotHasKey(self::KEY, $this->paymentInfo, $integration);
        }
    }

    public function testTheMismatchLogNamesTheOption(): void
    {
        $this->quoteMethod = 'freeshipping_freeshipping';

        $this->place($this->shippingLine(['ShippingMethodSecondaryOptionMerchantReference' => 'pickup-point-2']));

        $mismatch = $this->debugLogs['Placing the order with another delivery than the Qliro order carries'] ?? null;
        self::assertNotNull($mismatch);
        self::assertSame('pickup-point-2', $mismatch['extra']['qliro_secondary_option']);
        self::assertSame([self::METHOD], $mismatch['extra']['qliro_line_references']);
    }

    private function place(Item $shippingLine): void
    {
        $line = new Item();
        $line->setMerchantReference('518:SKU-A');
        $line->setType(QliroOrderItemInterface::TYPE_PRODUCT);
        $line->setQuantity(1);
        $line->setPricePerItemIncVat(125.0);
        $line->setPricePerItemExVat(100.0);

        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderId')->willReturn('900200');
        $link->method('getQuoteId')->willReturn(17);
        $link->method('getQliroOrderStatus')->willReturn(CheckoutStatusInterface::STATUS_IN_PROCESS);

        $qliroOrder = $this->createMock(QliroOrderInterface::class);
        $qliroOrder->method('getOrderId')->willReturn('900200');
        $qliroOrder->method('getCustomerCheckoutStatus')->willReturn(CheckoutStatusInterface::STATUS_IN_PROCESS);
        $qliroOrder->method('getCustomer')->willReturn($this->createMock(QliroOrderCustomerInterface::class));
        $qliroOrder->method('getOrderItems')->willReturn([$line, $shippingLine]);

        $this->placeOrder($link)->execute($qliroOrder);
    }

    /**
     * @param array $metadata
     */
    private function shippingLine(array $metadata): Item
    {
        $line = new Item();
        $line->setMerchantReference(self::METHOD);
        $line->setType(QliroOrderItemInterface::TYPE_SHIPPING);
        $line->setQuantity(1);
        $line->setPricePerItemIncVat(0.0);
        $line->setPricePerItemExVat(0.0);
        $line->setMetadata($metadata);

        return $line;
    }

    private function quote(): Quote&MockObject
    {
        $payment = $this->createMock(QuotePayment::class);
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function ($key, $value = null) use ($payment) {
                $this->paymentInfo[$key] = $value;

                return $payment;
            }
        );
        $payment->method('unsAdditionalInformation')->willReturnCallback(
            function ($key) use ($payment) {
                unset($this->paymentInfo[$key]);

                return $payment;
            }
        );

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod'])
            ->addMethods(['getShippingInclTax'])
            ->getMock();
        $address->method('getShippingMethod')->willReturnCallback(fn() => $this->quoteMethod);
        $address->method('getShippingInclTax')->willReturn(0.0);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(17);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }

    private function order(): Order&MockObject
    {
        $config = $this->createMock(OrderConfig::class);
        $config->method('getStateDefaultStatus')->willReturn('pending_payment');

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConfig', 'getId', 'getIncrementId', 'getPayment'])
            ->getMock();
        $order->method('getConfig')->willReturn($config);
        $order->method('getId')->willReturn(31);
        $order->method('getIncrementId')->willReturn('300000031');
        $order->method('getPayment')->willReturn($this->createMock(OrderPayment::class));
        $order->setState(Order::STATE_PENDING_PAYMENT);

        return $order;
    }

    private function placeOrder(LinkInterface $link): PlaceOrder
    {
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('get')->willReturn($this->quote());

        $quoteManagement = $this->createMock(QuoteManagement::class);
        $quoteManagement->method('setQuote')->willReturnSelf();

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQliroOrderId')->willReturn($link);

        $config = $this->createMock(Config::class);
        $config->method('getOrderStatus')->willReturn('qliro_new');
        $config->method('isUseIncrementIdAsReference')->willReturn(true);
        $config->method('isUnifaunEnabled')->willReturnCallback(fn() => $this->integration === 'unifaun');
        $config->method('isIngridEnabled')->willReturnCallback(fn() => $this->integration === 'ingrid');

        $logManager = $this->createMock(LogManager::class);
        $logManager->method('debug')->willReturnCallback(function ($message, array $context = []): void {
            $this->debugLogs[(string)$message] = $context;
        });

        $orderPlacer = $this->createMock(OrderPlacer::class);
        $orderPlacer->method('place')->willReturn($this->order());

        $orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $orderItemsBuilder->method('setQuote')->willReturnSelf();
        $orderItemsBuilder->method('create')->willReturn([]);

        return new PlaceOrder(
            $config,
            $this->createMock(MerchantInterface::class),
            $this->createMock(OrderManagementInterface::class),
            $this->createMock(QuoteFromOrderConverter::class),
            $linkRepository,
            $quoteRepository,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(ContainerMapper::class),
            $logManager,
            $orderPlacer,
            $this->createMock(Lock::class),
            $this->createMock(OrderSender::class),
            $quoteManagement,
            $this->createMock(PaymentManagement::class),
            $this->createMock(RecurringDataService::class),
            $this->createMock(OrganizationNumber::class),
            null,
            $this->createMock(PlacedOrderFinder::class),
            $this->createMock(DiscountLines::class),
            $orderItemsBuilder,
            new LineDifferences()
        );
    }
}

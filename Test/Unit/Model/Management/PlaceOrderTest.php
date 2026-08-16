<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * @see \Qliro\QliroOne\Model\Management\PlaceOrder::applyQliroOrderStatus
 *
 * PLIN-373: the merchant-reference update must target the Qliro order the caller resolved (not a
 * link re-loaded by Magento order id, which can be a reused/foreign row), and a failed update must
 * be logged as a failure rather than as "assigned" - while staying fire-once, since this is a
 * distributed module and the path runs on every status callback for every merchant's order.
 */
class PlaceOrderTest extends TestCase
{
    private Config&MockObject $qliroConfig;
    private OrderManagementInterface&MockObject $orderManagementApi;
    private LinkRepositoryInterface&MockObject $linkRepository;
    private ContainerMapper&MockObject $containerMapper;
    private LogManager&MockObject $logManager;
    private PlaceOrder $placeOrder;

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);
        $this->orderManagementApi = $this->createMock(OrderManagementInterface::class);
        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $this->containerMapper = $this->createMock(ContainerMapper::class);
        $this->logManager = $this->createMock(LogManager::class);

        $this->qliroConfig->method('isUseIncrementIdAsReference')->willReturn(false);
        $this->qliroConfig->method('getOrderStatus')->willReturn('processing');
        $this->containerMapper->method('fromArray')->willReturn(
            $this->createMock(AdminUpdateMerchantReferenceRequestInterface::class)
        );

        $this->placeOrder = new PlaceOrder(
            $this->qliroConfig,
            $this->createMock(MerchantInterface::class),
            $this->orderManagementApi,
            $this->createMock(QuoteFromOrderConverter::class),
            $this->linkRepository,
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->containerMapper,
            $this->logManager,
            $this->createMock(OrderPlacer::class),
            $this->createMock(Lock::class),
            $this->createMock(OrderSender::class),
            $this->createMock(QuoteManagement::class),
            $this->createMock(PaymentManagement::class),
            $this->createMock(RecurringDataService::class)
        );
    }

    /**
     * When the Qliro API returns nothing (updateMerchantReference() swallows its exception and
     * returns null on any error), the failure is logged rather than reported as "assigned" - and,
     * crucially for a fleet-wide module, it does NOT write a comment to the order or re-attempt: it
     * stays fire-once, so a benign persistent failure cannot spam every merchant's order history.
     */
    public function testFailedReferenceUpdateIsLoggedAndStaysFireOnce(): void
    {
        $this->logManager->expects(self::once())->method('critical');

        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn([]);
        // Fire-once preserved: the reference is still marked handled so it is not retried.
        $payment->expects(self::once())->method('setAdditionalInformation')
            ->with(self::callback(static fn ($info) => ($info['qliroone_updated_merchant_reference'] ?? false) === true));

        $order = $this->completedOrder($payment);
        // The module must not touch the order history on a failed reference update.
        $order->expects(self::never())->method('addCommentToStatusHistory');

        $this->orderManagementApi->method('updateMerchantReference')->willReturn(null);

        self::assertTrue($this->placeOrder->applyQliroOrderStatus($order, $this->completedLink()));
    }

    /**
     * On a successful update the reference is not logged as a failure and the payment is flagged.
     */
    public function testSuccessfulReferenceUpdateIsFlaggedAndNotLoggedAsFailure(): void
    {
        $this->logManager->expects(self::never())->method('critical');

        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn([]);
        $payment->expects(self::once())->method('setAdditionalInformation')
            ->with(self::callback(static fn ($info) => ($info['qliroone_updated_merchant_reference'] ?? false) === true));

        $order = $this->completedOrder($payment);

        $response = $this->createMock(AdminTransactionResponseInterface::class);
        $response->method('getPaymentTransactionId')->willReturn('999');
        $this->orderManagementApi->method('updateMerchantReference')->willReturn($response);

        self::assertTrue($this->placeOrder->applyQliroOrderStatus($order, $this->completedLink()));
    }

    /**
     * The OrderId sent to Qliro is the Qliro order id from the link the caller passed - the order
     * that was actually placed - and the buggy re-load by Magento order id is skipped entirely.
     */
    public function testUpdateTargetsThePassedLinksQliroOrderIdNotAReloadedOne(): void
    {
        $this->linkRepository->expects(self::never())->method('getByOrderId');

        // Re-declare fromArray here so we can assert the OrderId it is handed.
        $this->containerMapper = $this->createMock(ContainerMapper::class);
        $this->containerMapper->expects(self::once())->method('fromArray')
            ->with(self::callback(static fn ($data) => ($data['OrderId'] ?? null) === '275174114'))
            ->willReturn($this->createMock(AdminUpdateMerchantReferenceRequestInterface::class));

        $placeOrder = new PlaceOrder(
            $this->qliroConfig,
            $this->createMock(MerchantInterface::class),
            $this->orderManagementApi,
            $this->createMock(QuoteFromOrderConverter::class),
            $this->linkRepository,
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->containerMapper,
            $this->logManager,
            $this->createMock(OrderPlacer::class),
            $this->createMock(Lock::class),
            $this->createMock(OrderSender::class),
            $this->createMock(QuoteManagement::class),
            $this->createMock(PaymentManagement::class),
            $this->createMock(RecurringDataService::class)
        );

        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn([]);
        $order = $this->completedOrder($payment);

        $this->orderManagementApi->method('updateMerchantReference')
            ->willReturn($this->createMock(AdminTransactionResponseInterface::class));

        $placeOrder->applyQliroOrderStatus($order, $this->completedLink('275174114'));
    }

    private function completedLink(string $qliroOrderId = '275174114'): LinkInterface&MockObject
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderStatus')->willReturn(CheckoutStatusInterface::STATUS_COMPLETED);
        $link->method('getQliroOrderId')->willReturn($qliroOrderId);
        $link->method('getOrderId')->willReturn(15);

        return $link;
    }

    private function completedOrder(Payment&MockObject $payment): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(15);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('3000000015');
        $order->method('getCanSendNewEmailFlag')->willReturn(false);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }
}

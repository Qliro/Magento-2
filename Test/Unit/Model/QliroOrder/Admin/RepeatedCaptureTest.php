<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusSearchResultInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\OrderManagementApiException;
use Qliro\QliroOne\Model\Api\RequestId;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\MarkItemsAsShippedRequest;
use Qliro\QliroOne\Model\QliroOrder\Admin\OrderPaymentTransaction;
use Qliro\QliroOne\Model\QliroOrder\Admin\RepeatedCapture;
use Qliro\QliroOne\Model\QliroOrder\Admin\TransactionResponse;
use Qliro\QliroOne\Model\QliroOrder\Item as QliroOrderItem;
use Qliro\QliroOne\Model\QliroOrder\Shipment as QliroShipment;

/**
 * @see RepeatedCapture
 */
class RepeatedCaptureTest extends TestCase
{
    private const QLIRO_ORDER_ID = 5478412;

    private OrderManagementInterface&MockObject $api;

    /** @var OrderPaymentTransaction[] */
    private array $atQliro = [];

    /** @var int[] transaction ids on OM status rows */
    private array $rows = [];

    /** @var array<int, array> addFilter calls */
    private array $filters = [];

    /** @var string[] transaction ids of the order's invoices */
    private array $invoices = [];

    protected function setUp(): void
    {
        $this->api = $this->createMock(OrderManagementInterface::class);
        $this->api->method('getOrder')->willReturnCallback(function () {
            $order = $this->createMock(AdminOrderInterface::class);
            $order->method('getPaymentTransactions')->willReturn($this->atQliro);

            return $order;
        });
    }

    public function testTheOneUnrecordedCaptureOfTheAmountSentIsTheAnswer(): void
    {
        $this->atQliro = [
            $this->transaction(100, 'Preauthorization', 'Success', 368.0),
            $this->transaction(201, 'Capture', 'Success', 368.0),
        ];

        $answer = $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());

        self::assertSame(201, $answer->getPaymentTransactionId());
        self::assertSame('Created', $answer->getStatus());
    }

    /**
     * A second shipment of the same lines gets the same RequestId while total_paid stands still.
     * The first shipment's capture is on our books, so it is not taken for the second.
     */
    public function testACaptureOnAnOmRowIsNotTaken(): void
    {
        $this->atQliro = [$this->transaction(201, 'Capture', 'Success', 368.0)];
        $this->rows = [201];

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not be matched');

        $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());
    }

    public function testACaptureAnInvoiceCarriesIsNotTaken(): void
    {
        $this->atQliro = [$this->transaction(201, 'Capture', 'Success', 368.0)];
        $this->invoices = ['201'];

        $this->expectException(LocalizedException::class);

        $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());
    }

    public function testACaptureOfAnotherAmountIsNotTaken(): void
    {
        $this->atQliro = [$this->transaction(201, 'Capture', 'Success', 100.0)];

        $this->expectException(LocalizedException::class);

        $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());
    }

    public function testTwoCapturesOfTheAmountAreNotGuessed(): void
    {
        $this->atQliro = [
            $this->transaction(201, 'Capture', 'Success', 368.0),
            $this->transaction(202, 'Capture', 'Created', 368.0),
        ];
        $this->api->expects(self::never())->method('markItemsAsShipped');

        $this->expectException(LocalizedException::class);

        $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());
    }

    /**
     * Qliro failed the earlier attempt, so nothing moved: the capture goes again under a RequestId
     * of its own, the same one for the same failed capture
     */
    public function testAFailedEarlierAttemptIsCapturedAgain(): void
    {
        $this->atQliro = [
            $this->transaction(201, 'Capture', 'Error', 368.0),
            $this->transaction(203, 'Capture', 'Error', 368.0),
        ];
        $request = $this->request();
        $sent = (new TransactionResponse())->setPaymentTransactionId(204)->setStatus('Created');
        $this->api->expects(self::once())
            ->method('markItemsAsShipped')
            ->with(self::callback(fn ($sentRequest) => $sentRequest->getRequestId() === 'id(original|203)'))
            ->willReturn($sent);

        self::assertSame($sent, $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $request));
    }

    /**
     * The answer of the capture sent again was lost as well: its own capture is now the live one
     */
    public function testALiveCaptureWinsOverAFailedOne(): void
    {
        $this->atQliro = [
            $this->transaction(201, 'Capture', 'Error', 368.0),
            $this->transaction(204, 'Capture', 'Success', 368.0),
        ];
        $this->api->expects(self::never())->method('markItemsAsShipped');

        $answer = $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());

        self::assertSame(204, $answer->getPaymentTransactionId());
    }

    /**
     * The id derived from the failed capture was sent before, and what it captured is on the books
     */
    public function testARepeatOfTheCaptureSentAgainIsRefused(): void
    {
        $this->atQliro = [$this->transaction(201, 'Capture', 'Error', 368.0)];
        $terminal = new TerminalException('Duplicate request');
        $terminal->setQliroError('DUPLICATE_REQUEST', 'Duplicate request');
        $this->api->method('markItemsAsShipped')
            ->willThrowException(new OrderManagementApiException(__('Duplicate'), $terminal));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not be matched');

        $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());
    }

    /**
     * Qliro's notification of the lost capture saves a row with no record, which must not hide it
     */
    public function testOnlyRowsOfADocumentCountAsRecorded(): void
    {
        $this->atQliro = [$this->transaction(201, 'Capture', 'Success', 368.0)];

        $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());

        self::assertContains(['record_type', true, 'notnull'], $this->filters);
    }

    public function testAnUnreadableQliroOrderAsksForARetry(): void
    {
        $this->api = $this->createMock(OrderManagementInterface::class);
        $this->api->method('getOrder')->willThrowException(new \RuntimeException('timeout'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('could not be read');

        $this->repeatedCapture()->answer($this->order(), self::QLIRO_ORDER_ID, $this->request());
    }

    private function repeatedCapture(): RepeatedCapture
    {
        $statusRepository = $this->createMock(OrderManagementStatusRepositoryInterface::class);
        $statusRepository->method('getList')->willReturnCallback(function () {
            $result = $this->createMock(OrderManagementStatusSearchResultInterface::class);
            $result->method('getItems')->willReturn(array_map(
                fn (int $id) => $this->createConfiguredMock(OrderManagementStatus::class, ['getTransactionId' => $id]),
                $this->rows
            ));

            return $result;
        });

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnCallback(
            function (...$filter) use (&$searchCriteriaBuilder) {
                $this->filters[] = $filter;

                return $searchCriteriaBuilder;
            }
        );
        $searchCriteriaBuilder->method('create')->willReturn(new SearchCriteria());

        $idGenerator = $this->createMock(IdentityGeneratorInterface::class);
        $idGenerator->method('generateIdForData')->willReturnCallback(fn (string $data) => "id($data)");

        return new RepeatedCapture(
            $this->api,
            $statusRepository,
            $searchCriteriaBuilder,
            new RequestId($idGenerator),
            $this->createMock(LogManager::class)
        );
    }

    private function order(): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getInvoiceCollection')->willReturn(array_map(
            fn (string $id) => $this->createConfiguredMock(Invoice::class, ['getTransactionId' => $id]),
            $this->invoices
        ));

        return $order;
    }

    /**
     * Two units at 34.00 and the shipping at 300.00, 368.00 in all
     */
    private function request(): MarkItemsAsShippedRequest
    {
        $line = static fn (string $reference, float $price, int $qty) => (new QliroOrderItem())
            ->setMerchantReference($reference)
            ->setPricePerItemIncVat($price)
            ->setQuantity($qty);

        return (new MarkItemsAsShippedRequest())
            ->setOrderId(self::QLIRO_ORDER_ID)
            ->setRequestId('original')
            ->setShipments([(new QliroShipment())->setOrderItems([
                $line('5:SKU', 34.0, 2),
                $line('SHIPPING', 300.0, 1),
            ])]);
    }

    private function transaction(int $id, string $type, string $status, float $amount): OrderPaymentTransaction
    {
        return (new OrderPaymentTransaction())
            ->setPaymentTransactionId($id)
            ->setType($type)
            ->setStatus($status)
            ->setAmount($amount);
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Api\RequestId;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\AbstractManagement;

/**
 * Answers a capture Qliro refused as DUPLICATE_REQUEST: an earlier attempt under the same RequestId
 * was booked and its answer lost.
 *
 * Only a capture this order holds no record of can be that attempt, and only one of the amount sent.
 * When Qliro failed it, the capture is sent again under a RequestId derived from the failed one.
 */
class RepeatedCapture
{
    private const TYPE_CAPTURE = 'Capture';

    private const FAILED = [
        QliroOrderManagementStatusInterface::STATUS_ERROR,
        QliroOrderManagementStatusInterface::STATUS_CANCELLED,
    ];

    /**
     * @param OrderManagementInterface $orderManagementApi
     * @param OrderManagementStatusRepositoryInterface $statusRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param RequestId $requestId
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly OrderManagementInterface $orderManagementApi,
        private readonly OrderManagementStatusRepositoryInterface $statusRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly RequestId $requestId,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * The answer the earlier attempt would have had, or the answer of a capture sent again
     *
     * @param Order $order
     * @param int $qliroOrderId
     * @param AdminMarkItemsAsShippedRequestInterface $request
     * @return AdminTransactionResponseInterface
     * @throws LocalizedException
     * @throws ClientException when the capture sent again fails
     */
    public function answer(
        Order $order,
        int $qliroOrderId,
        AdminMarkItemsAsShippedRequestInterface $request
    ): AdminTransactionResponseInterface {
        $sent = $this->total($request);
        $live = [];
        $failed = [];

        foreach ($this->unrecordedCaptures($order, $qliroOrderId) as $id => $transaction) {
            if (abs((float)$transaction->getAmount() - $sent) >= 0.005) {
                continue;
            }

            if (in_array($transaction->getStatus(), self::FAILED, true)) {
                $failed[] = $id;
            } else {
                $live[] = $id;
            }
        }

        if (count($live) === 1) {
            $this->logManager->info(
                'Capture was booked by an earlier attempt, answering with its transaction',
                ['extra' => ['order_id' => $order->getId(), 'transaction_id' => $live[0]]]
            );

            return $this->response($live[0]);
        }

        if ($live === [] && $failed !== []) {
            return $this->sendAgain($order, $qliroOrderId, $request, max($failed));
        }

        throw $this->unmatched($order, $qliroOrderId, $live);
    }

    /**
     * @param Order $order
     * @param int $qliroOrderId
     * @param int[] $matches
     * @return LocalizedException
     */
    private function unmatched(Order $order, int $qliroOrderId, array $matches): LocalizedException
    {
        $this->logManager->critical(
            'Qliro already booked this capture, but it matches no single capture at Qliro',
            ['extra' => ['order_id' => $order->getId(), 'qliro_order_id' => $qliroOrderId, 'matches' => $matches]]
        );

        return new LocalizedException(
            __(
                'Qliro already received this capture of order %1, but it could not be matched to one '
                . 'capture at Qliro. Check the order in Qliro Merchant Hub before capturing it another way.',
                $qliroOrderId
            )
        );
    }

    /**
     * Qliro failed the earlier attempt, so nothing was captured. The new RequestId repeats for the
     * same failed capture, so a lost answer of this one is recognised as well.
     *
     * @param Order $order
     * @param int $qliroOrderId
     * @param AdminMarkItemsAsShippedRequestInterface $request
     * @param int $failedTransactionId
     * @return AdminTransactionResponseInterface
     * @throws ClientException
     * @throws LocalizedException
     */
    private function sendAgain(
        Order $order,
        int $qliroOrderId,
        AdminMarkItemsAsShippedRequestInterface $request,
        int $failedTransactionId
    ): AdminTransactionResponseInterface {
        $request->setRequestId($this->requestId->forRequest([$request->getRequestId(), (string)$failedTransactionId]));

        $this->logManager->info(
            'The earlier attempt of this capture failed at Qliro, capturing again',
            ['extra' => ['order_id' => $order->getId(), 'failed_transaction_id' => $failedTransactionId]]
        );

        try {
            return $this->orderManagementApi->markItemsAsShipped($request, $order->getStoreId());
        } catch (ClientException $exception) {
            // Sent under this id before, and its capture is on the books or failed as well
            $previous = $exception->getPrevious();

            if ($previous instanceof TerminalException
                && $previous->getQliroErrorCode() === AbstractManagement::QLIRO_ERROR_DUPLICATE_REQUEST
            ) {
                throw $this->unmatched($order, $qliroOrderId, []);
            }

            throw $exception;
        }
    }

    /**
     * The captures at Qliro that neither a document's OM status row nor an invoice holds
     *
     * @param Order $order
     * @param int $qliroOrderId
     * @return array<int, \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface>
     * @throws LocalizedException
     */
    private function unrecordedCaptures(Order $order, int $qliroOrderId): array
    {
        try {
            $qliroOrder = $this->orderManagementApi->getOrder(
                $qliroOrderId,
                $order->getStoreId(),
                Config::API_PROFILE_BACKGROUND
            );
        } catch (\Throwable $exception) {
            throw new LocalizedException(
                __(
                    'Qliro already received this capture of order %1, but the order at Qliro could not '
                    . 'be read. Try again.',
                    $qliroOrderId
                ),
                $exception instanceof \Exception ? $exception : null
            );
        }

        $recorded = $this->recordedTransactions($order, $qliroOrderId);
        $captures = [];

        foreach (($qliroOrder ? $qliroOrder->getPaymentTransactions() : null) ?: [] as $transaction) {
            $id = (int)$transaction->getPaymentTransactionId();

            if ($id && $transaction->getType() === self::TYPE_CAPTURE && !isset($recorded[$id])) {
                $captures[$id] = $transaction;
            }
        }

        return $captures;
    }

    /**
     * @param Order $order
     * @param int $qliroOrderId
     * @return array<int, true>
     */
    private function recordedTransactions(Order $order, int $qliroOrderId): array
    {
        $recorded = [];
        $search = $this->searchCriteriaBuilder
            ->addFilter(OrderManagementStatusInterface::FIELD_QLIRO_ORDER_ID, $qliroOrderId)
            // A row of Qliro's own notification has no record, the lost capture gets one too
            ->addFilter(OrderManagementStatusInterface::FIELD_RECORD_TYPE, true, 'notnull')
            ->create();

        foreach ($this->statusRepository->getList($search)->getItems() as $row) {
            if ($row->getTransactionId()) {
                $recorded[(int)$row->getTransactionId()] = true;
            }
        }

        foreach ($order->getInvoiceCollection() as $invoice) {
            if (ctype_digit((string)$invoice->getTransactionId())) {
                $recorded[(int)$invoice->getTransactionId()] = true;
            }
        }

        return $recorded;
    }

    /**
     * @param AdminMarkItemsAsShippedRequestInterface $request
     * @return float
     */
    private function total(AdminMarkItemsAsShippedRequestInterface $request): float
    {
        $total = 0.0;

        foreach ($request->getShipments() ?: [] as $shipment) {
            if (!$shipment instanceof QliroShipmentInterface) {
                continue;
            }

            foreach ($shipment->getOrderItems() ?: [] as $line) {
                $total += (float)$line->getPricePerItemIncVat() * (float)$line->getQuantity();
            }
        }

        return round($total, 2);
    }

    /**
     * @param int $transactionId
     * @return AdminTransactionResponseInterface
     */
    private function response(int $transactionId): AdminTransactionResponseInterface
    {
        $response = new TransactionResponse();
        $response->setPaymentTransactionId($transactionId);
        $response->setStatus('Created');

        return $response;
    }
}

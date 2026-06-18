<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder;

/**
 * Sends credit-memo refunds (AddItemsToInvoice Additions) to Qliro one capture at a time.
 *
 * Qliro's PSP accepts only one in-flight return per authorization, so the first Addition is sent
 * up front and the rest are queued on the payment and sent from the success callback.
 */
class SequentialRefundProcessor
{
    /** Key under which the not-yet-sent refund entries are stored on the payment. */
    public const KEY_PENDING_QUEUE = 'qliro_pending_refund_queue';

    /**
     * @param OrderManagementInterface $orderManagementApi
     * @param AddItemsToInvoiceBuilder $addItemsToInvoiceBuilder
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly OrderManagementInterface $orderManagementApi,
        private readonly AddItemsToInvoiceBuilder $addItemsToInvoiceBuilder,
        private readonly OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory,
        private readonly OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly OrderPaymentRepositoryInterface $paymentRepository,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Send the first Addition and queue the rest on the payment. Each entry carries its vat_rate
     * so later Additions can be rebuilt without a credit memo in context.
     *
     * @param Payment $payment
     * @param array $entries List of ['payment_transaction_id' => int, 'amount' => float, 'vat_rate' => float]
     * @return void
     * @throws LocalizedException
     */
    public function start(Payment $payment, array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $first = array_shift($entries);

        // Queue the rest before sending; the credit memo flow persists the payment on commit.
        $this->setQueue($payment, $entries);

        $this->sendEntry($payment, $first);
    }

    /**
     * Send the next queued Addition, if any. Called from the success callback handler.
     *
     * @param Payment $payment
     * @return bool True when a queued Addition was sent, false when the queue was empty.
     * @throws LocalizedException
     */
    public function continueQueue(Payment $payment): bool
    {
        $queue = $this->getQueue($payment);

        if (empty($queue)) {
            return false;
        }

        $next = array_shift($queue);

        // Persist the shrunken queue before sending so a fast callback cannot double-send.
        $this->setQueue($payment, $queue);
        $this->paymentRepository->save($payment);

        $this->sendEntry($payment, $next);

        return true;
    }

    /**
     * Discard any remaining queued Additions (used when a return is rejected by the PSP).
     *
     * @param Payment $payment
     * @return array The entries that were dropped, for reporting.
     */
    public function abort(Payment $payment): array
    {
        $queue = $this->getQueue($payment);

        if (!empty($queue)) {
            $this->setQueue($payment, []);
            $this->paymentRepository->save($payment);
        }

        return $queue;
    }

    /**
     * Build and send a single-Addition AddItemsToInvoice request for one capture.
     *
     * @param Payment $payment
     * @param array $entry ['payment_transaction_id' => int, 'amount' => float, 'vat_rate' => float]
     * @return void
     * @throws LocalizedException
     */
    private function sendEntry(Payment $payment, array $entry): void
    {
        $order = $payment->getOrder();
        $link = $this->linkRepository->getByOrderId($order->getId());

        $request = $this->addItemsToInvoiceBuilder
            ->setPayment($payment)
            ->setAllocation([$entry])
            ->create();

        $results = $this->orderManagementApi->addItemsToInvoice($request, $order->getStoreId());

        if (empty($results)) {
            throw new LocalizedException(__('Unable to refund'));
        }

        foreach ($results as $result) {
            $this->saveOmStatus($payment, $link, $result);

            if ($result->getStatus() != QliroOrderManagementStatusInterface::STATUS_CREATED) {
                throw new LocalizedException(__('Unable to refund'));
            }
        }
    }

    /**
     * Save an order-management-status row so the resulting TransactionStatus callback is matched.
     *
     * @param Payment $payment
     * @param \Qliro\QliroOne\Api\Data\LinkInterface $link
     * @param \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface $result
     * @return void
     */
    private function saveOmStatus($payment, $link, $result): void
    {
        try {
            /** @var \Qliro\QliroOne\Model\OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();
            $omStatus->setRecordId($payment->getId());
            $omStatus->setRecordType(OrderManagementStatusInterface::ADD_ITEMS_TO_INVOICE);
            $omStatus->setTransactionId($result->getPaymentTransactionId());
            $omStatus->setTransactionStatus(QliroOrderManagementStatusInterface::STATUS_CREATED);
            $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
            $omStatus->setMessage('Refund requested with add items to invoice');
            $omStatus->setQliroOrderId($link->getQliroOrderId());

            $this->orderManagementStatusRepository->save($omStatus);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'payment_id' => $payment->getId(),
                    ],
                ]
            );
        }
    }

    /**
     * @param Payment $payment
     * @return array
     */
    private function getQueue(Payment $payment): array
    {
        $value = $payment->getAdditionalInformation(self::KEY_PENDING_QUEUE);

        return is_array($value) ? $value : [];
    }

    /**
     * @param Payment $payment
     * @param array $queue
     * @return void
     */
    private function setQueue(Payment $payment, array $queue): void
    {
        $payment->setAdditionalInformation(self::KEY_PENDING_QUEUE, array_values($queue));
    }
}

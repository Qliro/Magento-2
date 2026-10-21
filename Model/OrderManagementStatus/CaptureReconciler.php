<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\OrderManagementStatus;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface as QliroStatus;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Management\TransactionStatus;

/**
 * Applies the status Qliro holds for a capture whose callback was lost.
 *
 * Qliro confirms a capture within a second, while the shipment or invoice that requested it may
 * still be being saved, so the callback finds no record and Qliro does not send it again. The
 * status is read from Qliro and handled as that callback would have been.
 */
class CaptureReconciler
{
    public const SETTLED = [
        QliroStatus::STATUS_SUCCESS,
        QliroStatus::STATUS_ERROR,
        QliroStatus::STATUS_CANCELLED,
    ];

    /**
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderManagement $orderManagementApi
     * @param QliroOrderManagementStatusInterfaceFactory $notificationFactory
     * @param TransactionStatus $transactionStatus
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagement $orderManagementApi,
        private readonly QliroOrderManagementStatusInterfaceFactory $notificationFactory,
        private readonly TransactionStatus $transactionStatus
    ) {
    }

    /**
     * The capture as Qliro holds it, null until Qliro has settled it
     *
     * @param OrderManagementStatusInterface $capture
     * @return AdminOrderPaymentTransactionInterface|null
     * @throws \Exception
     */
    public function findSettled(OrderManagementStatusInterface $capture): ?AdminOrderPaymentTransactionInterface
    {
        $qliroOrder = $this->orderManagementApi->getOrder(
            $capture->getQliroOrderId(),
            $this->order($capture)->getStoreId(),
            Config::API_PROFILE_BACKGROUND
        );

        foreach (($qliroOrder ? $qliroOrder->getPaymentTransactions() : null) ?: [] as $transaction) {
            if ((int)$transaction->getPaymentTransactionId() === (int)$capture->getTransactionId()) {
                return in_array($transaction->getStatus(), self::SETTLED, true) ? $transaction : null;
            }
        }

        return null;
    }

    /**
     * Handle the status as the callback would have, true when it was applied
     *
     * @param OrderManagementStatusInterface $capture
     * @param AdminOrderPaymentTransactionInterface $transaction
     * @return bool
     * @throws \Exception
     */
    public function apply(
        OrderManagementStatusInterface $capture,
        AdminOrderPaymentTransactionInterface $transaction
    ): bool {
        /** @var \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $notification */
        $notification = $this->notificationFactory->create();
        $notification->setOrderId($capture->getQliroOrderId())
            ->setMerchantReference($this->order($capture)->getIncrementId())
            ->setPaymentTransactionId($capture->getTransactionId())
            ->setPaymentType($transaction->getType())
            ->setStatus($transaction->getStatus())
            ->setAmount($transaction->getAmount())
            ->setCurrency($transaction->getCurrency());

        $this->transactionStatus->handle($notification);

        return $this->transactionStatus->isHandled($capture->getTransactionId(), $transaction->getStatus());
    }

    /**
     * @param OrderManagementStatusInterface $capture
     * @return bool
     * @throws \Exception
     */
    public function reconcile(OrderManagementStatusInterface $capture): bool
    {
        $transaction = $this->findSettled($capture);

        return $transaction !== null && $this->apply($capture, $transaction);
    }

    /**
     * The order through the link, as the callback finds it, so a shipment and an invoice capture
     * are read the same way
     *
     * @param OrderManagementStatusInterface $capture
     * @return OrderInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function order(OrderManagementStatusInterface $capture): OrderInterface
    {
        $link = $this->linkRepository->getByQliroOrderId($capture->getQliroOrderId(), false);

        return $this->orderRepository->get($link->getOrderId());
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Admin;

use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order\Shipment;
use Qliro\QliroOne\Api\Admin\OrderServiceInterface;
use Qliro\QliroOne\Api\Client\OrderManagement\OrderReaderInterface;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\MerchantNotification as MerchantNotificationManagement;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Management\QliroOrder as QliroOrderManagement;
use Qliro\QliroOne\Model\Management\SavedCreditCard as SavedCreditCardManagement;
use Qliro\QliroOne\Model\Management\TransactionStatus as TransactionStatusManagement;

/**
 * Admin-side / post-placement order operations.
 *
 * Delegates to the focused Management classes. No quote is involved.
 */
class OrderService implements OrderServiceInterface
{
    public function __construct(
        private readonly QliroOrderManagement $qliroOrderManagement,
        private readonly OrderReaderInterface $orderManagementApi,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LogManager $logManager,
        private readonly PaymentManagement $paymentManagement,
        private readonly TransactionStatusManagement $transactionStatusManagement,
        private readonly MerchantNotificationManagement $merchantNotificationManagement,
        private readonly SavedCreditCardManagement $savedCreditCardManagement
    ) {
    }

    /**
     * @inheritDoc
     */
    public function cancelQliroOrder(int $qliroOrderId): AdminTransactionResponseInterface
    {
        return $this->qliroOrderManagement->cancel($qliroOrderId);
    }

    /**
     * @inheritDoc
     */
    public function getAdminQliroOrder(int $qliroOrderId): ?AdminOrderInterface
    {
        $qliroOrder = null;

        try {
            $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
            $this->logManager->setMerchantReference($link->getReference());
            $qliroOrder = $this->orderManagementApi->getOrder($qliroOrderId);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => isset($link) ? $link->getOrderId() : $qliroOrderId,
                    ],
                ]
            );
        }

        return $qliroOrder;
    }

    /**
     * @inheritDoc
     */
    public function captureByInvoice(InfoInterface $payment, float $amount): void
    {
        $this->paymentManagement->captureByInvoice($payment, $amount);
    }

    /**
     * @inheritDoc
     */
    public function captureByShipment(Shipment $shipment): void
    {
        $this->paymentManagement->captureByShipment($shipment);
    }

    /**
     * @inheritDoc
     */
    public function refundByInvoice(InfoInterface $payment, float $amount): void
    {
        $this->paymentManagement->refundByInvoice($payment, $amount);
    }

    /**
     * @inheritDoc
     */
    public function handleTransactionStatus(array $qliroOrderManagementStatus): array
    {
        return $this->transactionStatusManagement->handle($qliroOrderManagementStatus);
    }

    /**
     * @inheritDoc
     */
    public function merchantNotification(array $container): array
    {
        return $this->merchantNotificationManagement->execute($container);
    }

    /**
     * @inheritDoc
     */
    public function updateOrderSavedCreditCard(array $notification): array
    {
        return $this->savedCreditCardManagement->update($notification);
    }
}

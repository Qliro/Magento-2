<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Sets the Magento order number on the Qliro order, once per order
 */
class MerchantReference
{
    public const KEY_SENT = 'qliroone_updated_merchant_reference';

    /**
     * @var Config
     */
    private $qliroConfig;

    /**
     * @var ContainerMapper
     */
    private $containerMapper;

    /**
     * @var OrderManagementInterface
     */
    private $orderManagementApi;

    /**
     * @var OrderPaymentRepositoryInterface
     */
    private $paymentRepository;

    /**
     * @var LogManager
     */
    private $logManager;

    /**
     * @param Config $qliroConfig
     * @param ContainerMapper $containerMapper
     * @param OrderManagementInterface $orderManagementApi
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param LogManager $logManager
     */
    public function __construct(
        Config $qliroConfig,
        ContainerMapper $containerMapper,
        OrderManagementInterface $orderManagementApi,
        OrderPaymentRepositoryInterface $paymentRepository,
        LogManager $logManager
    ) {
        $this->qliroConfig = $qliroConfig;
        $this->containerMapper = $containerMapper;
        $this->orderManagementApi = $orderManagementApi;
        $this->paymentRepository = $paymentRepository;
        $this->logManager = $logManager;
    }

    /**
     * Send the order number unless it was sent before
     *
     * The mark is saved before the call, on its own: the order save that follows can fail, and
     * Qliro then repeats its callback, which must not send the number again.
     *
     * @param Order $order
     * @param LinkInterface $link
     * @return void
     */
    public function sendOnce(Order $order, LinkInterface $link): void
    {
        $payment = $order->getPayment();

        if (!$payment || $payment->getAdditionalInformation(self::KEY_SENT)) {
            return;
        }

        $payment->setAdditionalInformation(self::KEY_SENT, true);

        // The reference is the increment id already, no call to repeat
        if ($this->qliroConfig->isUseIncrementIdAsReference((int)$order->getStoreId())) {
            return;
        }

        try {
            $this->paymentRepository->save($payment);
        } catch (\Exception $exception) {
            // The order save still keeps the mark, the order status must not wait for it
            $this->logManager->critical($exception, ['extra' => ['order_id' => $order->getId()]]);
        }

        /** @var AdminUpdateMerchantReferenceRequestInterface $request */
        $request = $this->containerMapper->fromArray(
            [
                'OrderId' => $link->getQliroOrderId(),
                'NewMerchantReference' => $order->getIncrementId(),
            ],
            AdminUpdateMerchantReferenceRequestInterface::class
        );

        $response = $this->orderManagementApi->updateMerchantReference($request, $order->getStoreId());
        $context = [
            'qliro_order_id' => $link->getQliroOrderId(),
            'order_id' => $order->getId(),
            'new_merchant_reference' => $order->getIncrementId(),
        ];

        if ($response === null) {
            $this->logManager->critical(
                new \RuntimeException('Failed to set the Magento order reference on the Qliro One order'),
                ['extra' => $context]
            );

            return;
        }

        $this->logManager->debug(
            'New merchant reference was assigned to the Qliro One order',
            $context + ['payment_transaction_id' => $response->getPaymentTransactionId() ?: 'unknown']
        );
    }
}

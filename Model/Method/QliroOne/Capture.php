<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Method\QliroOne;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Admin\OrderServiceInterface;

use Magento\Payment\Gateway\Command;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Command\ResultInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;

/**
 * Class Capture for QliroOne payment method
 */
readonly class Capture implements CommandInterface
{
    /**
     * Class constructor
     *
     * @param OrderServiceInterface $qliroManagement
     * @param Config $qliroConfig
     * @param LogManager $logManager
     */
    public function __construct(
        private OrderServiceInterface $qliroManagement,
        private Config                $qliroConfig,
        private LogManager            $logManager
    ) {
    }

    /**
     * Capture command
     *
     * @param array $commandSubject
     *
     * @return ResultInterface|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(array $commandSubject): ?ResultInterface
    {
        /** @var InfoInterface $payment */
        $payment = $commandSubject['payment']->getPayment();
        $amount = $commandSubject['amount'];

        try {
            /** @var Order $order */
            $order = $payment->getOrder();
            $captureOnInvoice = $this->qliroConfig->shouldCaptureOnInvoice($order ? $order->getStoreId() : null);
            $skipCapture = (bool) $payment->getData(PaymentManagement::QLIRO_SKIP_ACTUAL_CAPTURE);

            $this->logManager->debug('Capture::execute called', [
                'extra' => [
                    'order_id'          => $order ? $order->getId() : null,
                    'increment_id'      => $order ? $order->getIncrementId() : null,
                    'amount'            => $amount,
                    'capture_on_invoice' => $captureOnInvoice,
                    'skip_actual_capture' => $skipCapture,
                    'payment_txn_id'    => $payment->getTransactionId(),
                ],
            ]);

            if ($captureOnInvoice) {
                $this->qliroManagement->captureByInvoice($payment, $amount);
                $payment->setIsTransactionPending(false);
            } else {
                $this->logManager->debug('Capture::execute — capture_on_invoice disabled, marking transaction pending');
                $payment->setIsTransactionPending(true);
                $payment->setIsTransactionClosed(false);
            }
        } catch (\Exception $exception) {
            $this->logManager->debug('Capture::execute — exception: ' . $exception->getMessage(), [
                'extra' => [
                    'order_id' => isset($order) ? $order->getId() : null,
                ],
            ]);
            throw new LocalizedException(
                __('Unable to capture payment for this order.'),
                $exception
            );
        }

        return null;
    }
}

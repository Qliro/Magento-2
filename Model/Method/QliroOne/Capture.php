<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Model\Method\QliroOne;

use Magento\Framework\Exception\LocalizedException;
use Qliro\QliroOne\Api\Admin\OrderServiceInterface;

use Magento\Payment\Gateway\Command;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Command\ResultInterface;

/**
 * Class Capture for QliroOne payment method
 */
class Capture implements CommandInterface
{
    /**
     * Class constructor
     *
     * @param OrderServiceInterface $qliroManagement
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     */
    public function __construct(
        private readonly OrderServiceInterface $qliroManagement,
        private readonly \Qliro\QliroOne\Model\Config $qliroConfig
    ) {
    }

    /**
     * Capture command
     *
     * @param array $commandSubject
     *
     * @return ResultInterface|null
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(array $commandSubject)
    {
        /** @var \Magento\Payment\Model\InfoInterface $payment */
        $payment = $commandSubject['payment']->getPayment();
        $amount = $commandSubject['amount'];

        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $payment->getOrder();
            if ($this->qliroConfig->shouldCaptureOnInvoice($order ? $order->getStoreId() : null)) {
                $this->qliroManagement->captureByInvoice($payment, $amount);
            }
        } catch (\Exception $exception) {
            throw new LocalizedException(
                __('Unable to capture payment for this order.')
            );
        }

        return $this;
    }
}

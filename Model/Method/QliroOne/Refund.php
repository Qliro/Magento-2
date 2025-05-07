<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Model\Method\QliroOne;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Command\ResultInterface;
use Qliro\QliroOne\Model\Management;

/**
 * Class Refund for QliroOne payment method
 */
class Refund implements CommandInterface
{
    /**
     * @var Management
     */
    private $qliroManagement;

    /**
     * @param Management $qliroManagement
     */
    public function __construct(
        Management $qliroManagement
    ) {
        $this->qliroManagement = $qliroManagement;
    }

    /**
     * Refund command
     *
     * @param array $commandSubject
     *
     * @return ResultInterface|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(array $commandSubject)
    {
        /** @var \Magento\Payment\Model\InfoInterface $payment */
        $payment = $commandSubject['payment']->getPayment();
        $amount = $commandSubject['amount'];

        try {
            $this->qliroManagement->refundByInvoice($payment, $amount);
        } catch (\Exception $exception) {
            throw new LocalizedException(
                __('Unable to refund for this order: ' . $exception->getMessage())
            );
        }

        return null;
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\Data\CreditmemoInterface as Creditmemo;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

class RefundDiscountBuilder
{
    private ?Creditmemo $creditMemo = null;

    /**
     * Class constructor
     *
     * @param EventManager            $eventManager
     */
    public function __construct(
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * Sets the credit memo.
     *
     * @param Creditmemo $creditMemo
     * @return $this
     */
    public function setCreditMemo(Creditmemo $creditMemo): static
    {
        $this->creditMemo = $creditMemo;

        return $this;
    }

    /**
     * Create refund discount containers as plain arrays.
     *
     * @return array[]
     */
    public function create(): array
    {
        if (empty($this->creditMemo)) {
            throw new \LogicException('Credit memo entity is not set.');
        }

        $container = $this->getDiscounts();
        $result = isset($container['MerchantReference']) ? [$container] : [];
        $this->creditMemo = null;

        return $result;
    }

    /**
     * Build discount information for the current credit memo as a plain array.
     *
     * @return array
     */
    protected function getDiscounts(): array
    {
        $container = [];

        if ($this->creditMemo->getAdjustmentPositive() > 0) {
            $container = [
                'MerchantReference'  => sprintf(
                    'ReturnRefund_%s',
                    $this->creditMemo->getOrder()->getCreditmemosCollection()->getSize()
                ),
                'Description'        => 'Adjustment Refund',
                'PricePerItemIncVat' => -abs($this->creditMemo->getAdjustmentPositive()),
                'PricePerItemExVat'  => -abs($this->creditMemo->getAdjustmentPositive()),
                'Quantity'           => 1,
                'Type'               => QliroOrderItemInterface::TYPE_DISCOUNT,
            ];

            $this->eventManager->dispatch(
                'qliroone_refund_discount_build_after',
                [
                    'credit_memo' => $this->creditMemo,
                    'container'   => &$container,
                ]
            );
        }

        return $container;
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

/**
 * Class RefundFeeBuilder
 */
class RefundFeeBuilder
{
    private ?CreditmemoInterface $creditMemo = null;

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
     * Set a credit memo for data extraction
     *
     * @param CreditmemoInterface $creditMemo
     * @return $this
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo): static
    {
        $this->creditMemo = $creditMemo;

        return $this;
    }

    /**
     * Create a QliroOne refund fee container
     *
     * @return array[]
     */
    public function create(): array
    {
        if (empty($this->creditMemo)) {
            throw new \LogicException('Credit memo entity is not set.');
        }

        $container = $this->getAdjustmentFeeContainer();
        $result = isset($container['MerchantReference']) ? [$container] : [];
        $this->creditMemo = null;

        return $result;
    }

    /**
     * Get a credit memo adjustment fee container as a plain array.
     *
     * @return array
     */
    protected function getAdjustmentFeeContainer(): array
    {
        $container = [];

        if ($this->creditMemo->getAdjustmentNegative() > 0) {
            $container = [
                'MerchantReference'  => sprintf(
                    'ReturnFee_%s',
                    $this->creditMemo->getOrder()->getCreditmemosCollection()->getSize()
                ),
                'Description'        => 'Adjustment Fee',
                'PricePerItemIncVat' => abs($this->creditMemo->getAdjustmentNegative()),
                'PricePerItemExVat'  => abs($this->creditMemo->getAdjustmentNegative()),
                'Quantity'           => 1,
                'Type'               => QliroOrderItemInterface::TYPE_FEE,
            ];

            $this->eventManager->dispatch(
                'qliroone_refund_fee_build_after',
                [
                    'credit_memo' => $this->creditMemo,
                    'container'   => &$container,
                ]
            );
        }

        return $container;
    }
}

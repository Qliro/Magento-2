<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;

class RefundDiscountBuilder
{
    /**
     * @var QliroOrderItemInterfaceFactory
     */
    private $qliroOrderItemFactory;

    /**
     * @var ManagerInterface
     */
    private $eventManager;

    /**
     * @var CreditmemoInterface
     */
    private $creditMemo;

    /**
     * Constructor method for the class.
     *
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory Factory for creating Qliro order items
     * @param ManagerInterface $eventManager Event manager for handling events
     */
    public function __construct(
        QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        ManagerInterface $eventManager
    ) {
        $this->qliroOrderItemFactory = $qliroOrderItemFactory;
        $this->eventManager = $eventManager;
    }

    /**
     * Sets the credit memo.
     *
     * @param CreditmemoInterface $creditMemo The credit memo instance to be set
     * @return $this
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo)
    {
        $this->creditMemo = $creditMemo;

        return $this;
    }

    /**
     * Creates and returns an array of processed data based on the current credit memo entity.
     * Throws a LogicException if the credit memo entity is not set.
     *
     * @return array Processed data from the credit memo entity, including discounts.
     * @throws \LogicException If the credit memo entity is not set.
     */
    public function create()
    {
        if (empty($this->creditMemo)) {
            throw new \LogicException('Credit memo entity is not set.');
        }

        $result = [];
        $result[] = $this->getDiscounts();

        $this->creditMemo = null;

        return $result;
    }

    /**
     * Retrieves discount information for the current credit memo.
     *
     * This method creates a Qliro order item representing the discount applied
     * during the credit memo process. The discount is encapsulated as a single
     * item with relevant details such as price and type.
     *
     * @return QliroOrderItemInterface Returns an instance of QliroOrderItemInterface
     * containing discount details, including description, price, quantity, and type.
     */
    protected function getDiscounts()
    {
        $container = $this->qliroOrderItemFactory->create();


        if ($this->creditMemo->getAdjustmentPositive() > 0) {
            $container->setMerchantReference(
                sprintf("ReturnRefund_%s", $this->creditMemo->getOrder()->getCreditmemosCollection()->getSize())
            );
            $container->setDescription('Adjustment Refund');
            $container->setPricePerItemIncVat(-abs($this->creditMemo->getAdjustmentPositive()));
            $container->setPricePerItemExVat(-abs($this->creditMemo->getAdjustmentPositive()));
            $container->setQuantity(1);
            $container->setType(QliroOrderItemInterface::TYPE_DISCOUNT);

            $this->eventManager->dispatch(
                'qliroone_refund_discount_build_after',
                [
                    'credit_memo' => $this->creditMemo,
                    'container' => $container,
                ]
            );
        }

        return $container;
    }
}

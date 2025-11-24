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

class RefundFeeBuilder
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
     * Inject dependencies
     *
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        ManagerInterface $eventManager
    ) {
        $this->qliroOrderItemFactory = $qliroOrderItemFactory;
        $this->eventManager = $eventManager;
    }

    /**
     * Set credit memo for data extraction
     *
     * @param CreditmemoInterface $creditMemo
     * @return $this
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo)
    {
        $this->creditMemo = $creditMemo;

        return $this;
    }

    /**
     * Create a QliroOne refund fee container
     *
     * @return QliroOrderItemInterface[]
     */
    public function create()
    {
        if (empty($this->creditMemo)) {
            throw new \LogicException('Credit memo entity is not set.');
        }

        $container = $this->getAdjustmentFeeContainer();
        $result = $container->getMerchantReference() ? [$container] : [];
        $this->creditMemo = null;

        return $result;
    }

    /**
     * Get credit memo adjustment fee container
     *
     * @return QliroOrderItemInterface
     */
    protected function getAdjustmentFeeContainer()
    {
        $container = $this->qliroOrderItemFactory->create();
        if ($this->creditMemo->getAdjustmentNegative() > 0) {
            /** @var QliroOrderItemInterface $container */
            $container->setMerchantReference(
                sprintf("ReturnFee_%s", $this->creditMemo->getOrder()->getCreditmemosCollection()->getSize())
            );
            $container->setDescription('Adjustment Fee');
            $container->setPricePerItemIncVat(abs($this->creditMemo->getAdjustmentNegative()));
            $container->setPricePerItemExVat(abs($this->creditMemo->getAdjustmentNegative()));
            $container->setQuantity(1);
            $container->setType(QliroOrderItemInterface::TYPE_FEE);

            $this->eventManager->dispatch(
                'qliroone_refund_fee_build_after',
                [
                    'credit_memo' => $this->creditMemo,
                    'container' => $container,
                ]
            );
        }

        return $container;
    }
}

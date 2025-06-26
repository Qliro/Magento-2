<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder\Handler;

use Magento\Framework\Event\ManagerInterface;
use Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;

/**
 * Applied Rules Handler class for order items builder
 */
class AppliedRulesHandler implements OrderItemHandlerInterface
{
    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory
     */
    private $qliroOrderItemFactory;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private $qliroHelper;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param \Qliro\QliroOne\Helper\Data $qliroHelper
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     */
    public function __construct(
        QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        QliroHelper $qliroHelper,
        ManagerInterface $eventManager
    ) {

        $this->qliroOrderItemFactory = $qliroOrderItemFactory;
        $this->qliroHelper = $qliroHelper;
        $this->eventManager = $eventManager;
    }

    /**
     * Handle specific type of order items and add them to the QliroOne order items list
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $orderItems
     * @param \Magento\Quote\Model\Quote $quote
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function handle($orderItems, $quote)
    {
//        $arrayAppliedRules = sprintf('DSC_%s', $quote->getAppliedRuleIds()?\str_replace(',', '_', (string)$quote->getAppliedRuleIds()):'');
//        $discountAmount = $quote->getSubtotalWithDiscount() - $quote->getSubtotal();
//        $formattedAmount = $this->qliroHelper->formatPrice($discountAmount);
//
//        if ($discountAmount) {
//            $rates = $quote->getShippingAddress()->getAppliedTaxes();
//            $discountAmountWithoutVat = $discountAmount;
//            if ($rates && is_array($rates)) {
//                $rate = current($rates);
//                if (isset($rate['percent'])) {
//                    $percent = (int)$rate['percent'];
//                    $discountAmountWithoutVat = ($discountAmount/ (100 + $percent)) * 100;
//                }
//            }
//            $formattedAmountWithoutVat = $this->qliroHelper->formatPrice($discountAmountWithoutVat);
//            /** @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface $qliroOrderItem */
//            $qliroOrderItem = $this->qliroOrderItemFactory->create();
//
//            $qliroOrderItem->setMerchantReference($arrayAppliedRules);
//            $qliroOrderItem->setDescription($arrayAppliedRules);
//            $qliroOrderItem->setType(QliroOrderItemInterface::TYPE_DISCOUNT);
//            $qliroOrderItem->setQuantity(1);
//            $qliroOrderItem->setPricePerItemIncVat(\abs($formattedAmount));
//            $qliroOrderItem->setPricePerItemExVat(\abs($formattedAmountWithoutVat));
//
//             Note that this event dispatch must be done for every implemented Handler
//            $this->eventManager->dispatch(
//                'qliroone_order_item_build_after',
//                [
//                    'quote' => $quote,
//                    'container' => $qliroOrderItem,
//                ]
//            );
//
//            if ($qliroOrderItem->getMerchantReference()) {
//                $orderItems[] = $qliroOrderItem;
//            }
//        }
        $discountAmount = $quote->getSubtotalWithDiscount() - $quote->getSubtotal();
        if ($discountAmount) {
            foreach ($quote->getItems() as $item) {
                $parentItem = $item;
                if ($item->getParentItemId()) {
                    $parentItem = $item->getParentItem();
                }
                if (!$parentItem->getAppliedRuleIds()) {
                    continue;
                }

                $discountAmount = $parentItem->getDiscountAmount() / $item->getQty() ;
                $discountAmountWithoutVat = $discountAmount;
                if ($item->getTaxPercent() > 0) {
                    $discountAmountWithoutVat = ($discountAmount/ (100 + $item->getTaxPercent())) * 100;
                }

                for ($i = 1; $i <= $item->getQty(); $i++) {
                    $arrayAppliedRules = sprintf('DSC_%s:%s:%s:%s', $item->getAppliedRuleIds()?\str_replace(',', '_', (string)$item->getAppliedRuleIds()):'', $parentItem->getItemId(), $item->getSku(), $i);
                    /** @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface $qliroOrderItem */
                    $qliroOrderItem = $this->qliroOrderItemFactory->create();
                    $qliroOrderItem->setMerchantReference($arrayAppliedRules);
                    $qliroOrderItem->setDescription($arrayAppliedRules);
                    $qliroOrderItem->setType(QliroOrderItemInterface::TYPE_DISCOUNT);
                    $qliroOrderItem->setQuantity(1);
                    $qliroOrderItem->setPricePerItemIncVat(\abs($this->qliroHelper->formatPrice($discountAmount)));
                    $qliroOrderItem->setPricePerItemExVat(\abs($this->qliroHelper->formatPrice($discountAmountWithoutVat)));

                    $this->eventManager->dispatch(
                        'qliroone_order_item_build_after',
                        [
                            'quote' => $quote,
                            'container' => $qliroOrderItem,
                        ]
                    );

                    if ($qliroOrderItem->getMerchantReference()) {
                        $orderItems[] = $qliroOrderItem;
                    }
                }
            }
        }

        return $orderItems;
    }
}

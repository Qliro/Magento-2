<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Config;

/**
 * Finds the Magento order an earlier placement attempt already committed for a Qliro order.
 *
 * Core commits the order before it runs the submit observers and saves the quote, so an attempt
 * can fail after the order exists. Placing again from the same quote would make a second order.
 */
class PlacedOrderFinder
{
    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * The order placed from the quote for this Qliro order, or null when there is none
     *
     * @param int $quoteId
     * @param string $qliroOrderId
     * @return Order|null
     */
    public function find(int $quoteId, string $qliroOrderId): ?Order
    {
        if ($quoteId <= 0 || $qliroOrderId === '') {
            return null;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('quote_id', $quoteId)
            ->create();

        foreach ($this->orderRepository->getList($searchCriteria)->getItems() as $order) {
            $payment = $order->getPayment();
            $orderQliroId = $payment
                ? (string)$payment->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID)
                : '';

            // The buyer may have paid the same quote with another method
            if ($orderQliroId === $qliroOrderId) {
                return $order;
            }
        }

        return null;
    }
}

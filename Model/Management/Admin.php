<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * QliroOne management class
 */
class Admin extends AbstractManagement
{
    /**
     * @var \Qliro\QliroOne\Api\Client\OrderManagementInterface
     */
    private $orderManagementApi;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * Inject dependencies
     *
     * @param OrderManagementInterface $orderManagementApi
     * @param LinkRepositoryInterface $linkRepository
     * @param LogManager $logManager
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        OrderManagementInterface $orderManagementApi,
        LinkRepositoryInterface $linkRepository,
        LogManager $logManager,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->orderManagementApi = $orderManagementApi;
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Get Admin Qliro order after it was already placed
     *
     * @param int $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\AdminOrderInterface
     */
    public function getQliroOrder($qliroOrderId)
    {
        $qliroOrder = null; // Placeholder, QliroOne order will never be returned as null

        try {
            $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
            $this->logManager->setMerchantReference($link->getReference());
            $qliroOrder = $this->orderManagementApi->getOrder($qliroOrderId, $this->resolveStoreId($link));
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => isset($link) ? $link->getOrderId() : $qliroOrderId,
                    ],
                ]
            );
        }

        return $qliroOrder;
    }

    /**
     * The store the link was placed in, or null when its order cannot be read
     *
     * @param LinkInterface $link
     * @return int|null
     */
    private function resolveStoreId(LinkInterface $link)
    {
        $orderId = $link->getOrderId();

        if (empty($orderId)) {
            return null;
        }

        try {
            return (int)$this->orderRepository->get($orderId)->getStoreId();
        } catch (\Exception $exception) {
            // Without the store the request falls back to the default scope, which is what every
            // caller got before, so this only reports why
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'order_id' => $orderId,
                        'qliro_order_id' => $link->getQliroOrderId(),
                    ],
                ]
            );

            return null;
        }
    }
}

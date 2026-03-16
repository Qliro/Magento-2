<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Config;

/**
 * Mark Items As Shipped Request Builder class
 */
class ShipmentMarkItemsAsShippedRequestBuilder
{
    /**
     * @var \Magento\Sales\Model\Order\Payment
     */
    private $payment;

    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @var \Magento\Sales\Model\Order\Shipment
     */
    private $shipment;

    /**
     * Class constructor
     *
     * @param AdminMarkItemsAsShippedRequestInterfaceFactory $requestFactory
     * @param LinkRepositoryInterface $linkRepository
     * @param LogManager $logManager
     * @param ShipmentShipmentsBuilder $shipmentsBuilder
     * @param Config $qliroConfig
     */
    public function __construct(
        private readonly AdminMarkItemsAsShippedRequestInterfaceFactory $requestFactory,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LogManager $logManager,
        private readonly ShipmentShipmentsBuilder $shipmentsBuilder,
        private readonly Config $qliroConfig
    ) {
    }

    /**
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     */
    public function setShipment($shipment)
    {
        $this->shipment = $shipment;

        /** @var \Magento\Sales\Model\Order $order */
        $this->order = $this->shipment->getOrder();

        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $this->payment = $this->order->getPayment();
    }

    /**
     * @return \Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface
     */
    public function create()
    {
        if (empty($this->order)) {
            throw new \LogicException('Order entity is not set.');
        }

        $request = $this->prepareRequest();

        $this->payment = null;
        $this->order = null;
        $this->shipment = null;

        return $request;
    }

    /**
     * Prepare a new request
     *
     * @return \Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface
     */
    private function prepareRequest()
    {
        /** @var \Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface $request */
        $request = $this->requestFactory->create();

        try {
            $link = $this->linkRepository->getByOrderId($this->order->getId());

            $request->setMerchantApiKey($this->qliroConfig->getMerchantApiKey($this->order->getStoreId()));
            $request->setCurrency($this->order->getOrderCurrencyCode());
            $request->setOrderId($link->getQliroOrderId());

            $this->shipmentsBuilder->setShipment($this->shipment);
            $shipments = $this->shipmentsBuilder->create();

            $request->setShipments($shipments);

        } catch (NoSuchEntityException $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'link_id' => $link->getId(),
                        'quote_id' => $link->getQuoteId(),
                        'qliro_order_id' => $link->getQliroOrderId(),
                    ],
                ]
            );

        }

        return $request;
    }
}

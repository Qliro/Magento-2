<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Shipment;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterfaceFactory as AdminMarkItemsAsShippedRequestFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface as LinkRepository;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Config;

/**
 * Mark Items As Shipped Request Builder class
 */
class ShipmentMarkItemsAsShippedRequestBuilder
{
    private ?Payment $payment = null;
    private ?Order $order = null;
    private ?Shipment $shipment = null;

    /**
     * Class constructor
     *
     * @param AdminMarkItemsAsShippedRequestFactory            $requestFactory
     * @param LinkRepository                                   $linkRepository
     * @param LogManager                                       $logManager
     * @param ShipmentShipmentsBuilder                         $shipmentsBuilder
     * @param Config                                           $qliroConfig
     */
    public function __construct(
        private readonly AdminMarkItemsAsShippedRequestFactory $requestFactory,
        private readonly LinkRepository                        $linkRepository,
        private readonly LogManager                            $logManager,
        private readonly ShipmentShipmentsBuilder              $shipmentsBuilder,
        private readonly Config                                $qliroConfig
    ) {
    }

    /**
     * @param Shipment $shipment
     */
    public function setShipment(Shipment $shipment): void
    {
        $this->shipment = $shipment;

        /** @var Order $order */
        $this->order = $this->shipment->getOrder();

        /** @var Payment $payment */
        $this->payment = $this->order->getPayment();
    }

    /**
     * @return AdminMarkItemsAsShippedRequestInterface
     */
    public function create(): AdminMarkItemsAsShippedRequestInterface
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
     * @return AdminMarkItemsAsShippedRequestInterface
     */
    private function prepareRequest(): AdminMarkItemsAsShippedRequestInterface
    {
        /** @var AdminMarkItemsAsShippedRequestInterface $request */
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

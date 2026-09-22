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
use Qliro\QliroOne\Model\Api\RequestId;
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
     * @var \Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterfaceFactory
     */
    private $requestFactory;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentShipmentsBuilder
     */
    private $shipmentsBuilder;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Model\Api\RequestId
     */
    private $requestId;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterfaceFactory $requestFactory
     * @param \Qliro\QliroOne\Api\LinkRepositoryInterface $linkRepository
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     * @param \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentShipmentsBuilder $shipmentsBuilder
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Model\Api\RequestId $requestId
     */
    public function __construct(
        AdminMarkItemsAsShippedRequestInterfaceFactory $requestFactory,
        LinkRepositoryInterface $linkRepository,
        LogManager $logManager,
        ShipmentShipmentsBuilder $shipmentsBuilder,
        Config $qliroConfig,
        RequestId $requestId
    ) {
        $this->requestFactory = $requestFactory;
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->shipmentsBuilder = $shipmentsBuilder;
        $this->qliroConfig = $qliroConfig;
        $this->requestId = $requestId;
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
     * What the shipment is asking Qliro to settle, flattened for the request id
     *
     * @param \Qliro\QliroOne\Api\Data\QliroShipmentInterface[] $shipments
     * @return array
     */
    private function shippedLineParts(array $shipments): array
    {
        $parts = [];

        foreach ($shipments as $shipment) {
            foreach ($shipment->getOrderItems() ?: [] as $line) {
                $parts[] = (string)$line->getMerchantReference();
                $parts[] = (float)$line->getQuantity();
                $parts[] = (float)$line->getPricePerItemIncVat();
            }
        }

        return $parts;
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

            /*
             * The same id if this capture is sent again, so a capture that timed out after Qliro
             * booked it is not booked a second time when the merchant ships again. The shipment
             * itself has no id yet, it is saved after this call, so what repeats is the order,
             * what it had already paid, and the lines being captured.
             */
            $request->setRequestId(
                $this->requestId->forRequest(array_merge(
                    [
                        'mark-items-as-shipped',
                        (string)$this->order->getIncrementId(),
                        (float)$this->order->getTotalPaid(),
                    ],
                    $this->shippedLineParts($shipments)
                ))
            );
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

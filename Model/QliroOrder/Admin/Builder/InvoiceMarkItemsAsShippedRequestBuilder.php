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
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceShipmentsBuilder;

/**
 * Mark Items As Shipped Request Builder class
 */
class InvoiceMarkItemsAsShippedRequestBuilder
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
     * @var float
     */
    private $amount;

    /**
     * @var \Qliro\QliroOne\Model\Api\RequestId
     */
    private $requestId;

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
     * @var \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceShipmentsBuilder
     */
    private $shipmentsBuilder;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterfaceFactory $requestFactory
     * @param \Qliro\QliroOne\Api\LinkRepositoryInterface $linkRepository
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     * @param \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceShipmentsBuilder $shipmentsBuilder
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Model\Api\RequestId $requestId
     */
    public function __construct(
        AdminMarkItemsAsShippedRequestInterfaceFactory $requestFactory,
        LinkRepositoryInterface $linkRepository,
        LogManager $logManager,
        InvoiceShipmentsBuilder $shipmentsBuilder,
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
     * @param \Magento\Sales\Model\Order\Payment $payment
     */
    public function setPayment($payment)
    {
        $this->payment = $payment;

        /** @var \Magento\Sales\Model\Order $order */
        $this->order = $this->payment->getOrder();
    }

    /**
     * Amount from Magento Capture call, is not actually used, but could be used for double checking...
     *
     * @param float $amount
     */
    public function setAmount($amount)
    {
        $this->amount = $amount;
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

            $this->shipmentsBuilder->setPayment($this->payment);
            $shipments = $this->shipmentsBuilder->create();

            $request->setShipments($shipments);

            /*
             * The same id if this capture is sent again. A capture that timed out after Qliro
             * booked it leaves no invoice behind, and the merchant invoices again: under a fresh
             * id that is the buyer's money taken twice. What repeats is the order, what it had
             * already paid before this attempt, and the amount being captured.
             */
            $request->setRequestId(
                $this->requestId->forRequest([
                    'mark-items-as-shipped',
                    (string)$this->order->getIncrementId(),
                    (float)$this->order->getTotalPaid(),
                    (float)$this->amount,
                ])
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

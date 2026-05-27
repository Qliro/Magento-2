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
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterfaceFactory as AdminMarkItemsAsShippedRequestFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Config;

/**
 * Mark Items As Shipped Request Builder class
 */
class InvoiceMarkItemsAsShippedRequestBuilder
{
    private ?Payment $payment = null;
    private ?Order $order = null;
    private ?float $amount = null;

    /**
     * Class constructor
     *
     * @param AdminMarkItemsAsShippedRequestFactory            $requestFactory
     * @param LinkRepositoryInterface                          $linkRepository
     * @param LogManager                                       $logManager
     * @param InvoiceShipmentsBuilder                          $shipmentsBuilder
     * @param Config                                           $qliroConfig
     */
    public function __construct(
        private readonly AdminMarkItemsAsShippedRequestFactory $requestFactory,
        private readonly LinkRepositoryInterface               $linkRepository,
        private readonly LogManager                            $logManager,
        private readonly InvoiceShipmentsBuilder               $shipmentsBuilder,
        private readonly Config                                $qliroConfig
    ) {
    }

    /**
     * @param Payment $payment
     */
    public function setPayment(Payment $payment): void
    {
        $this->payment = $payment;

        /** @var Order $order */
        $this->order = $this->payment->getOrder();
    }

    /**
     * Amount from Magento Capture call is not actually used, but could be used for double-checking...
     *
     * @param float $amount
     */
    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
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
        $this->amount = null;

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

            $this->shipmentsBuilder->setPayment($this->payment);
            $shipments = $this->shipmentsBuilder->create();

            $request->setShipments($shipments);

        } catch (NoSuchEntityException $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'order_id' => $this->order->getId(),
                        'increment_id' => $this->order->getIncrementId(),
                    ],
                ]
            );
        }

        return $request;
    }
}

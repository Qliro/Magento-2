<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\AppliedRulesHandler;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreditMemoItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\RefundFeeBuilder;

class ReturnWithItemsBuilder
{
    /**
     * @var Payment
     */
    private $payment;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var AdminReturnWithItemsRequestInterfaceFactory
     */
    private $adminReturnWithItemsRequestFactory;

    /**
     * @var OrderItemsBuilder
     */
    private $orderItemsBuilder;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var AppliedRulesHandler
     */
    private $appliedRulesHandler;

    /**
     * @var ShippingFeeHandler
     */
    private $shippingFeeHandler;

    /**
     * @var CreditMemoItemsBuilder
     */
    private $creditMemoItemsBuilder;
    private RefundFeeBuilder $refundFeeBuilder;


    /**
     * Inject dependencies
     *
     * @param LinkRepositoryInterface $linkRepository
     * @param LogManager $logManager
     * @param Config $qliroConfig
     * @param AdminReturnWithItemsRequestInterfaceFactory $adminReturnWithItemsRequestFactory
     * @param OrderItemsBuilder $orderItemsBuilder
     * @param CartRepositoryInterface $cartRepository
     * @param AppliedRulesHandler $appliedRulesHandler
     * @param ShippingFeeHandler $shippingFeeHandler
     * @param CreditMemoItemsBuilder $creditMemoItemsBuilder
     * @param RefundFeeBuilder $refundFeeBuilder
     */
    public function __construct(
        LinkRepositoryInterface                     $linkRepository,
        LogManager                                  $logManager,
        Config                                      $qliroConfig,
        AdminReturnWithItemsRequestInterfaceFactory $adminReturnWithItemsRequestFactory,
        OrderItemsBuilder                           $orderItemsBuilder,
        CartRepositoryInterface                     $cartRepository,
        AppliedRulesHandler                         $appliedRulesHandler,
        ShippingFeeHandler                          $shippingFeeHandler,
        CreditMemoItemsBuilder                      $creditMemoItemsBuilder,
        RefundFeeBuilder                            $refundFeeBuilder
    )
    {
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->qliroConfig = $qliroConfig;
        $this->adminReturnWithItemsRequestFactory = $adminReturnWithItemsRequestFactory;
        $this->orderItemsBuilder = $orderItemsBuilder;
        $this->cartRepository = $cartRepository;
        $this->appliedRulesHandler = $appliedRulesHandler;
        $this->shippingFeeHandler = $shippingFeeHandler;
        $this->creditMemoItemsBuilder = $creditMemoItemsBuilder;
        $this->refundFeeBuilder = $refundFeeBuilder;
    }


    /**
     * @return AdminReturnWithItemsRequestInterface
     */
    public function create()
    {
        if (empty($this->payment)) {
            throw new \LogicException('Payment entity is not set.');
        }

        $request = $this->prepareRequest();

        $this->payment = null;

        return $request;
    }

    /**
     * @param Payment $payment
     * @return $this
     */
    public function setPayment(Payment $payment)
    {
        $this->payment = $payment;

        return $this;
    }

    /**
     * @return AdminReturnWithItemsRequestInterface
     */
    private function prepareRequest()
    {
        /** @var AdminReturnWithItemsRequestInterface $request */
        $request = $this->adminReturnWithItemsRequestFactory->create();

        $order = $this->payment->getOrder();
        if ($this->payment->getCreditmemo()->getShippingAmount() > 0) {
            $order->setFirstCaptureFlag(true);
        }

        try {
            $link = $this->linkRepository->getByOrderId($order->getId());
            $quote = $this->cartRepository->get($order->getQuoteId());

            $request->setMerchantApiKey(
                $this->qliroConfig->getMerchantApiKey($order->getStoreId())
            )->setOrderId(
                $link->getQliroOrderId()
            )->setCurrency(
                $order->getOrderCurrencyCode()
            )->setPaymentTransactionId(
                $this->payment->getParentTransactionId()
            )->setOrderItems(
                $this->shippingFeeHandler->handle(
                    $this->creditMemoItemsBuilder
                        ->setQuote($quote)
                        ->setCreditMemo($this->payment->getCreditmemo())
                        ->create(),
                    $order
                )
            )->setFees(
                [
                    $this->refundFeeBuilder
                        ->setCreditMemo($this->payment->getCreditmemo())
                        ->create()
                ]
            );

        } catch (NoSuchEntityException|ClientException $e) {
            $this->logManager->debug(
                $e,
                [
                    'extra' => [
                        'order_id' => $order->getId(),
                        'quote_id' => $order->getQuoteId(),
                    ],
                ]
            );
        }

        return $request;
    }
}

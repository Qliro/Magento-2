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
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreditMemoItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\RefundFeeBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\InvoiceFeeHandler;
use Qliro\QliroOne\Api\Admin\CreditMemo\InvoiceFeeTotalValidatorInterface;
use Qliro\QliroOne\Model\QliroOrder\Builder\RefundDiscountBuilder;

class ReturnWithItemsBuilder
{
    /**
     * @var Payment
     */
    private $payment;

    /**
     * Class constructor
     *
     * @param LinkRepositoryInterface $linkRepository
     * @param LogManager $logManager
     * @param Config $qliroConfig
     * @param AdminReturnWithItemsRequestInterfaceFactory $adminReturnWithItemsRequestFactory
     * @param CartRepositoryInterface $cartRepository
     * @param ShippingFeeHandler $shippingFeeHandler
     * @param CreditMemoItemsBuilder $creditMemoItemsBuilder
     * @param RefundFeeBuilder $refundFeeBuilder
     * @param InvoiceFeeHandler $invoiceFeeHandler
     * @param InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator
     * @param RefundDiscountBuilder $refundDiscountBuilder
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LogManager $logManager,
        private readonly Config $qliroConfig,
        private readonly AdminReturnWithItemsRequestInterfaceFactory $adminReturnWithItemsRequestFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ShippingFeeHandler $shippingFeeHandler,
        private readonly CreditMemoItemsBuilder $creditMemoItemsBuilder,
        private readonly RefundFeeBuilder $refundFeeBuilder,
        private readonly InvoiceFeeHandler $invoiceFeeHandler,
        private readonly InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator,
        private readonly RefundDiscountBuilder $refundDiscountBuilder
    ) {
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
        $order->setFirstCaptureFlag(true);

        try {
            $link = $this->linkRepository->getByOrderId($order->getId());
            $quote = $this->cartRepository->get($order->getQuoteId());

            $orderItems = $this->creditMemoItemsBuilder
                    ->setQuote($quote)
                    ->setCreditMemo($this->payment->getCreditmemo())
                    ->create();

            if ($this->payment->getCreditmemo()->getShippingAmount() > 0) {
                $orderItems =  $this->shippingFeeHandler->handle($orderItems, $order);
            }

            if ($this->invoiceFeeTotalValidator->setCreditMemo(
                $this->payment->getCreditmemo())->validate(true, true)
            ) {
                $orderItems = $this->invoiceFeeHandler->handle($orderItems, $order);
            }

            $request->setMerchantApiKey(
                $this->qliroConfig->getMerchantApiKey($order->getStoreId())
            )->setOrderId(
                $link->getQliroOrderId()
            )->setCurrency(
                $order->getOrderCurrencyCode()
            )->setPaymentTransactionId(
                $this->payment->getParentTransactionId()
            )->setOrderItems(
                $orderItems
            )->setFees(
                $this->refundFeeBuilder
                    ->setCreditMemo($this->payment->getCreditmemo())
                    ->create()
            )->setDiscounts(
                $this->refundDiscountBuilder
                    ->setCreditMemo($this->payment->getCreditmemo())
                    ->create()
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

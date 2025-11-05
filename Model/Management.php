<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationResponseInterface;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardNotificationInterface;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardResponseInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface;
use Qliro\QliroOne\Model\Management\AbstractManagement;
use Qliro\QliroOne\Model\Management\Admin;
use Qliro\QliroOne\Model\Management\CheckoutStatus;
use Qliro\QliroOne\Model\Management\HtmlSnippet;
use Qliro\QliroOne\Model\Management\Payment;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\QliroOrder;
use Qliro\QliroOne\Model\Management\Quote;
use Qliro\QliroOne\Model\Management\ShippingMethod;
use Qliro\QliroOne\Model\Management\TransactionStatus;
use Qliro\QliroOne\Model\Management\MerchantNotification;
use Qliro\QliroOne\Model\Management\SavedCreditCard;

/**
 * QliroOne management class
 */
class Management extends AbstractManagement implements ManagementInterface
{
    /**
     * @var Admin
     */
    private $adminManagement;
    /**
     * @var CheckoutStatus
     */
    private $checkoutStatusManagement;
    /**
     * @var HtmlSnippet
     */
    private $htmlSnippetManagement;
    /**
     * @var Payment
     */
    private $paymentManagement;
    /**
     * @var PlaceOrder
     */
    private $placeOrderManagement;
    /**
     * @var QliroOrder
     */
    private $qliroOrderManagement;
    /**
     * @var Quote
     */
    private Quote $quoteManagement;
    /**
     * @var ShippingMethod
     */
    private $shippingMethodManagement;
    /**
     * @var TransactionStatus
     */
    private $transactionStatusManagement;

    /**
     * @var MerchantNotification
     */
    private $merchantNotificationManagement;

    /**
     * @var SavedCreditCard
     */
    private $savedCreditCardManagement;

    /**
     * Inject dependencies
     *
     * @param Admin $adminManagement
     * @param CheckoutStatus $checkoutStatusManagement
     * @param HtmlSnippet $htmlSnippetManagement
     * @param Payment $paymentManagement
     * @param PlaceOrder $placeOrderManagement
     * @param QliroOrder $qliroOrderManagement
     * @param Quote $quote
     * @param ShippingMethod $shippingMethodManagement
     * @param TransactionStatus $transactionStatusManagement
     * @param MerchantNotification $merchantNotificationManagement
     * @param SavedCreditCard $savedCreditCardManagement
     */
    public function __construct(
        Admin $adminManagement,
        CheckoutStatus $checkoutStatusManagement,
        HtmlSnippet $htmlSnippetManagement,
        Payment $paymentManagement,
        PlaceOrder $placeOrderManagement,
        QliroOrder $qliroOrderManagement,
        Quote $quoteManagement,
        ShippingMethod $shippingMethodManagement,
        TransactionStatus $transactionStatusManagement,
        MerchantNotification $merchantNotificationManagement,
        SavedCreditCard $savedCreditCardManagement
    ) {
        $this->adminManagement = $adminManagement;
        $this->checkoutStatusManagement = $checkoutStatusManagement;
        $this->htmlSnippetManagement = $htmlSnippetManagement;
        $this->paymentManagement = $paymentManagement;
        $this->placeOrderManagement = $placeOrderManagement;
        $this->qliroOrderManagement = $qliroOrderManagement;
        $this->quoteManagement = $quoteManagement;
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->transactionStatusManagement = $transactionStatusManagement;
        $this->merchantNotificationManagement = $merchantNotificationManagement;
        $this->savedCreditCardManagement = $savedCreditCardManagement;
    }

    /**
     * Fetch a QliroOne order and return it as a container
     *
     * @param bool $allowRecreate
     * @return QliroOrderInterface
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function getQliroOrder($allowRecreate = true)
    {
        return $this->qliroOrderManagement->setQuote($this->getQuote())->get($allowRecreate);
    }

    /**
     * Fetch an HTML snippet from QliroOne order
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return string
     */
    public function getHtmlSnippet()
    {
        return $this->htmlSnippetManagement->setQuote($this->getQuote())->get();
    }

    /**
     * Update quote with received data in the container and return a list of available shipping methods
     *
     * @param UpdateShippingMethodsNotificationInterface $updateContainer
     * @return \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface
     */
    public function getShippingMethods(UpdateShippingMethodsNotificationInterface $updateContainer)
    {
        return $this->shippingMethodManagement->get($updateContainer);
    }

    /**
     * Update quote with received data in the container and validate QliroOne order
     *
     * @param ValidateOrderNotificationInterface $validateContainer
     * @return \Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface
     */
    public function validateQliroOrder(ValidateOrderNotificationInterface $validateContainer)
    {
        return $this->qliroOrderManagement->validate($validateContainer);
    }

    /**
     * Poll for Magento order placement and return order increment ID if successful
     *
     * @return Order
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function pollPlaceOrder()
    {
        return $this->placeOrderManagement->setQuote($this->getQuote())->poll();
    }

    /**
     * @param CheckoutStatusInterface $checkoutStatus
     * @return \Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterface
     */
    public function checkoutStatus(CheckoutStatusInterface $checkoutStatus)
    {
        return $this->checkoutStatusManagement->update($checkoutStatus);
    }

    /**
     * Get a QliroOne order, update the quote, then place Magento order
     * If placeOrder is successful, it returns the Magento Order
     * If an error occurs it returns null
     * If it's not possible to aquire lock, it returns false
     *
     * @param QliroOrderInterface $qliroOrder
     * @param string $state
     * @return Order
     * @throws \Qliro\QliroOne\Model\Exception\FailToLockException
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function placeOrder(QliroOrderInterface $qliroOrder, $state = Order::STATE_PENDING_PAYMENT)
    {
        return $this->placeOrderManagement->setQuote($this->getQuote())->execute($qliroOrder, $state);
    }

    /**
     * Update customer with data from QliroOne frontend callback
     *
     * @param array $customerData
     * @throws \Exception
     */
    public function updateCustomer($customerData)
    {
        $this->quoteManagement->setQuote($this->getQuote())->updateCustomer($customerData);
    }

    /**
     * Update selected shipping method in quote
     * Return true in case shipping method was set, or false if the quote is virtual or method was not changed
     *
     * @param string $code
     * @param string|null $secondaryOption
     * @param float|null $price
     * @return bool
     * @throws \Exception
     */
    public function updateShippingMethod($code, $secondaryOption = null, $price = null)
    {
        return $this->shippingMethodManagement->setQuote($this->getQuote())->update($code, $secondaryOption, $price);
    }

    /**
     * Update shipping price in quote
     * Return true in case shipping price was set, or false if the quote is virtual or update didn't happen
     *
     * @param float|null $price
     * @return bool
     * @throws \Exception
     */
    public function updateShippingPrice($price)
    {
        return $this->quoteManagement->setQuote($this->getQuote())->updateShippingPrice($price);
    }

    /**
     * Update selected shipping method in quote
     * Return true in case shipping method was set, or false if the quote is virtual or method was not changed
     *
     * @param float $fee
     * @return bool
     * @throws \Exception
     */
    public function updateFee($fee)
    {
        return $this->quoteManagement->setQuote($this->getQuote())->updateFee($fee);
    }

    /**
     * Cancel QliroOne order
     *
     * @param int $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function cancelQliroOrder($qliroOrderId)
    {
        return $this->qliroOrderManagement->cancel($qliroOrderId);
    }

    /**
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws \Exception
     */
    public function captureByInvoice($payment, $amount)
    {
        $this->paymentManagement->captureByInvoice($payment, $amount);
    }

    /**
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @return void
     * @throws \Exception
     */
    public function captureByShipment($shipment)
    {
        $this->paymentManagement->captureByShipment($shipment);
    }

    /**
     * Handles Order Management Status Transaction notifications
     *
     * @param \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $qliroOrderManagementStatus
     * @return \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatusResponse
     */
    public function handleTransactionStatus($qliroOrderManagementStatus)
    {
        return $this->transactionStatusManagement->handle($qliroOrderManagementStatus);
    }

    /**
     * Get Admin Qliro order after it was already placed
     *
     * @param int $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\AdminOrderInterface
     */
    public function getAdminQliroOrder($qliroOrderId)
    {
        return $this->adminManagement->getQliroOrder($qliroOrderId);
    }

    /**
     * @inheritDoc
     */
    public function merchantNotification(MerchantNotificationInterface $container): MerchantNotificationResponseInterface
    {
        return $this->merchantNotificationManagement->execute($container);
    }

    /**
     * Update saved Credit Card
     *
     * @param MerchantSavedCreditCardNotificationInterface $merchantSavedCreditCardNotification
     * @return MerchantSavedCreditCardResponseInterface
     */
    public function updateOrderSavedCreditCard(
        MerchantSavedCreditCardNotificationInterface $merchantSavedCreditCardNotification
    ): MerchantSavedCreditCardResponseInterface {
        return $this->savedCreditCardManagement->update($merchantSavedCreditCardNotification);
    }

    /**
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws \Exception
     */
    public function refundByInvoice($payment, $amount)
    {
        $this->paymentManagement->refundByInvoice($payment, $amount);
    }
}

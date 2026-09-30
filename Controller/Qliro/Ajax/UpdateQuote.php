<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Exception\UnsupportedQuoteException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * Update Quote AJAX controller action class
 */
class UpdateQuote extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private $dataHelper;

    /**
     * @var \Qliro\QliroOne\Model\Security\AjaxToken
     */
    private $ajaxToken;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Api\ManagementInterface
     */
    private $qliroManagement;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * Inject dependnecies
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Helper\Data $dataHelper
     * @param \Qliro\QliroOne\Model\Security\AjaxToken $ajaxToken
     * @param \Qliro\QliroOne\Api\ManagementInterface $qliroManagement
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
    public function __construct(
        Context $context,
        Config $qliroConfig,
        Data $dataHelper,
        AjaxToken $ajaxToken,
        ManagementInterface $qliroManagement,
        Session $checkoutSession,
        Manager $logManager
    ) {
        parent::__construct($context);
        $this->dataHelper = $dataHelper;
        $this->ajaxToken = $ajaxToken;
        $this->qliroConfig = $qliroConfig;
        $this->qliroManagement = $qliroManagement;
        $this->checkoutSession = $checkoutSession;
        $this->logManager = $logManager;
    }

    /**
     * Dispatch the action
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function execute()
    {
        if (!$this->qliroConfig->isActive()) {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => (string)__('Qliro One is not active.')],
                403,
                null,
                'AJAX:UPDATE_QUOTE:ERROR_INACTIVE'
            );
        }

        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->getRequest();

        $quote = $this->checkoutSession->getQuote();
        $this->logManager->setMerchantReferenceFromQuote($quote);
        $this->ajaxToken->setQuote($quote);

        if (!$this->ajaxToken->verifyToken($request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => (string)__('Security token is incorrect.')],
                401,
                null,
                'AJAX:UPDATE_QUOTE:ERROR_TOKEN'
            );
        }

        $this->dataHelper->readPreparedPayload($request, 'AJAX:UPDATE_QUOTE');

        try {
            $this->qliroManagement->setQuote($quote)->getQliroOrder();
        } catch (UnsupportedQuoteException $exception) {
            /*
             * The cart or the country is what the buyer has to change, and the message says which,
             * so it is passed on rather than replaced. Without this the exception left the
             * controller as a Magento error page, and the browser showed `Internal Server Error`
             * in place of the sentence the refusal exists to deliver. This refresh runs on every
             * checkout, so the same was true of the fractional quantity refusal (PLIN-367).
             */
            return $this->dataHelper->sendPreparedPayload(
                ['error' => $exception->getMessage()],
                400,
                null,
                'AJAX:UPDATE_QUOTE:UNSUPPORTED'
            );
        } catch (TerminalException $exception) {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => (string)__('Cannot fetch Qliro One order.')],
                400,
                null,
                'AJAX:UPDATE_QUOTE:ERROR'
            );
        }

        if ($quote->isVirtual()) {
            $billingAddress = $quote->getBillingAddress();
            $fee = $billingAddress->getQlirooneFee();
            $shippingCost = 0;
        } else {
            $shippingAddress = $quote->getShippingAddress();
            $fee = $shippingAddress->getQlirooneFee();
            $shippingCost = $shippingAddress->getShippingInclTax();
        }

        return $this->dataHelper->sendPreparedPayload(
            [
                'order' => [
                    'totalPrice' => $quote->getGrandTotal() - $fee - $shippingCost,
                ],
                /*
                 * The buyer's email reaches the browser here as well as through the customer
                 * callback, because this response is handled on the cart refresh, which every
                 * checkout runs and which a store that replaces the customer handler with one of
                 * its own still calls. Without it the native checkout's own email stays empty and
                 * every core call carrying it is refused. The key is one the log redaction masks
                 * by name, so it is written to the browser and not to the log.
                 */
                'email' => $quote->getCustomerEmail(),
            ],
            200,
            null,
            'AJAX:UPDATE_QUOTE'
        );
    }
}

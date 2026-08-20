<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResponseInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Quote\Agent;
use Qliro\QliroOne\Model\Quote\ShippingAddressFormDataBuilder;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * Update customer AJAX controller action class
 */
class UpdateCustomer extends \Magento\Framework\App\Action\Action
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
     * @var \Qliro\QliroOne\Model\Quote\Agent
     */
    private $quoteAgent;

    /**
     * @var \Qliro\QliroOne\Model\Quote\ShippingAddressFormDataBuilder
     */
    private $shippingAddressFormDataBuilder;

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
     * @param \Qliro\QliroOne\Model\Quote\Agent $quoteAgent
     * @param \Qliro\QliroOne\Model\Quote\ShippingAddressFormDataBuilder|null $shippingAddressFormDataBuilder
     */
    public function __construct(
        Context $context,
        Config $qliroConfig,
        Data $dataHelper,
        AjaxToken $ajaxToken,
        ManagementInterface $qliroManagement,
        Session $checkoutSession,
        Manager $logManager,
        Agent $quoteAgent,
        ?ShippingAddressFormDataBuilder $shippingAddressFormDataBuilder = null
    ) {
        parent::__construct($context);
        $this->dataHelper = $dataHelper;
        $this->ajaxToken = $ajaxToken;
        $this->qliroConfig = $qliroConfig;
        $this->qliroManagement = $qliroManagement;
        $this->checkoutSession = $checkoutSession;
        $this->logManager = $logManager;
        $this->quoteAgent = $quoteAgent;
        // Optional so a subclass calling parent::__construct() with the old signature keeps
        // working, resolved here because the frontend depends on the address being returned.
        $this->shippingAddressFormDataBuilder = $shippingAddressFormDataBuilder
            ?: ObjectManager::getInstance()->get(ShippingAddressFormDataBuilder::class);
    }

    /**
     * Dispatch the action
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     */
    public function execute()
    {
        if (!$this->qliroConfig->isActive()) {
            $this->logManager->debug('Qliro One is not enabled for ' . $this->getRequest()->getRequestUri());
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Qliro One is not active.')
                ],
                403,
                null,
                'AJAX:UPDATE_CUSTOMER:ERROR_INACTIVE'
            );
        }

        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->getRequest();

        $quote = $this->checkoutSession->getQuote();
        $this->logManager->setMerchantReferenceFromQuote($quote);
        $this->ajaxToken->setQuote($quote);
        $this->quoteAgent->store($quote);

        if (!$this->ajaxToken->verifyToken($request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Security token is incorrect.')
                ],
                401,
                null,
                'AJAX:UPDATE_CUSTOMER:ERROR_TOKEN'
            );
        }

        $data = $this->dataHelper->readPreparedPayload($request, 'AJAX:UPDATE_CUSTOMER');
        if (array_key_exists('address', $data) && is_null($data['address'])) {
            $data['address'] = [];
        }

        try {
            $this->logManager->debug('Starting to update customer in Qliro quote ' . $quote->getId());
            $applied = $this->qliroManagement->setQuote($quote)->updateCustomer($data);
            $this->logManager->debug('Finished to update customer in Qliro quote ' . $quote->getId());

            if (!$applied) {
                $this->logManager->notice(
                    'Customer payload from QliroOne had nothing to apply to the quote',
                    [
                        'extra' => [
                            'quote_id' => $quote->getId(),
                            // Names only. Qliro sends {"isMasked": true} instead of the address
                            // until the customer is identified, and a plain "has address" flag
                            // reads as true for that.
                            'address_fields' => array_keys($data['address'] ?? $data['Address'] ?? []),
                        ],
                    ]
                );
            }
        } catch (\Exception $exception) {
            $this->logManager->debug('Failed to update customer in Qliro quote ' . $quote->getId());
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Cannot update quote with customer data.')
                ],
                400,
                null,
                'AJAX:UPDATE_CUSTOMER:ERROR'
            );
        }

        return $this->dataHelper->sendPreparedPayload(
            [
                'status' => 'OK',
                // The frontend has no address form to read, so it takes the address from here
                'address' => $this->shippingAddressFormDataBuilder->build($quote),
            ],
            200,
            null,
            'AJAX:UPDATE_CUSTOMER'
        );
    }
}

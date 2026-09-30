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
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\QliroOrder\LinesTotal;
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
     * @var \Qliro\QliroOne\Model\QliroOrder\LinesTotal
     */
    private LinesTotal $linesTotal;

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
     * @param \Qliro\QliroOne\Model\QliroOrder\LinesTotal|null $linesTotal
     */
    public function __construct(
        Context $context,
        Config $qliroConfig,
        Data $dataHelper,
        AjaxToken $ajaxToken,
        ManagementInterface $qliroManagement,
        Session $checkoutSession,
        Manager $logManager,
        ?LinesTotal $linesTotal = null
    ) {
        parent::__construct($context);
        $this->dataHelper = $dataHelper;
        $this->ajaxToken = $ajaxToken;
        $this->qliroConfig = $qliroConfig;
        $this->qliroManagement = $qliroManagement;
        $this->checkoutSession = $checkoutSession;
        $this->logManager = $logManager;
        // Optional so a store constructing this controller with the old signature keeps working
        $this->linesTotal = $linesTotal ?? new LinesTotal();
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
        } catch (TerminalException $exception) {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => (string)__('Cannot fetch Qliro One order.')],
                400,
                null,
                'AJAX:UPDATE_QUOTE:ERROR'
            );
        }

        return $this->dataHelper->sendPreparedPayload(
            [
                'order' => [
                    // The same figure the order lines are built to add up to, see LinesTotal:
                    // the browser compares it against the Qliro order and keeps the checkout
                    // locked while the two disagree
                    'totalPrice' => $this->linesTotal->ofQuote($quote),
                ],
            ],
            200,
            null,
            'AJAX:UPDATE_QUOTE'
        );
    }
}

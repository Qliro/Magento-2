<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResponseInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\FailToLockException;
use Qliro\QliroOne\Model\Exception\OrderPlacementPendingException;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Quote\Agent;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Model\Success\Session;

/**
 * AJAX controller action class for triggering order placement and polling for pending
 */
class PollPending implements HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private Http $request;

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
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Model\Quote\Agent
     */
    private $quoteAgent;
    /**
     * @var Session
     */
    private $successSession;

    /**
     * Inject dependnecies
     *
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Helper\Data $dataHelper
     * @param \Qliro\QliroOne\Model\Security\AjaxToken $ajaxToken
     * @param \Qliro\QliroOne\Api\ManagementInterface $qliroManagement
     * @param \Qliro\QliroOne\Model\Quote\Agent $quoteAgent
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     * @param Session $successSession
     */
    public function __construct(
        Http $request,
        Config $qliroConfig,
        Data $dataHelper,
        AjaxToken $ajaxToken,
        ManagementInterface $qliroManagement,
        Agent $quoteAgent,
        Manager $logManager,
        Session $successSession
    ) {
        $this->request = $request;
        $this->dataHelper = $dataHelper;
        $this->ajaxToken = $ajaxToken;
        $this->qliroConfig = $qliroConfig;
        $this->qliroManagement = $qliroManagement;
        $this->logManager = $logManager;
        $this->quoteAgent = $quoteAgent;
        $this->successSession = $successSession;
    }

    /**
     * Dispatch the action
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     */
    public function execute()
    {
        if (!$this->qliroConfig->isActive()) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Qliro One is not active.')
                ],
                403,
                null,
                'AJAX:POLL_SUCCESS:ERROR_INACTIVE'
            );
        }

        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->request;

        $quote = $this->quoteAgent->fetchRelevantQuote();
        $this->logManager->setMerchantReferenceFromQuote($quote);
        $this->ajaxToken->setQuote($quote);

        if (!$quote) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('The cart was reset.')
                ],
                401,
                null,
                'AJAX:POLL_SUCCESS:ERROR_QUOTE'
            );
        }

        if (!$this->ajaxToken->verifyToken($request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Security token is incorrect.')
                ],
                401,
                null,
                'AJAX:POLL_SUCCESS:ERROR_TOKEN'
            );
        }

        $htmlSnippet = null;

        try {
            $order = $this->qliroManagement->setQuote($quote)->pollPlaceOrder();
            $htmlSnippet = $this->qliroManagement->getHtmlSnippet();
            $this->successSession->save($htmlSnippet, $order);
        } catch (TerminalException $exception) {
            $previousException = $exception->getPrevious();
            $isLockFailed = $previousException instanceof FailToLockException;
            $isStatusWrong = $previousException instanceof OrderPlacementPendingException;

            if ($isLockFailed || $isStatusWrong) {
                return $this->dataHelper->sendPreparedPayload(
                    ['status' => 'PENDING'],
                    200,
                    null,
                    'AJAX:POLL_SUCCESS:PENDING'
                );
            }

            $this->quoteAgent->clear();

            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Order cannot be placed. Please try again, or contact our Customer Service.')
                ],
                400,
                null,
                'AJAX:POLL_SUCCESS:ERROR'
            );
        }

        return $this->dataHelper->sendPreparedPayload(
            [
                'status' => 'OK',
                'htmlSnippet' => $htmlSnippet,
                'orderIncrementId' => $order->getIncrementId(),
            ],
            200,
            null,
            'AJAX:POLL_SUCCESS'
        );
    }
}

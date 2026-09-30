<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Quote\Api\CartRepositoryInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringPaymentsDataService;

/**
 * Set recurring order info AJAX controller action class
 * Only used when recurring orders is enabled in config
 */
class SetRecurring implements HttpPostActionInterface
{
    /**
     * @var Http
     */
    private Http $request;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private Data $dataHelper;

    /**
     * @var \Qliro\QliroOne\Model\Security\AjaxToken
     */
    private AjaxToken $ajaxToken;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private Config $qliroConfig;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    private Session $checkoutSession;

    /**
     * @var \Qliro\QliroOne\Service\RecurringPayments\Data
     */
    private RecurringPaymentsDataService $recurringPaymentsDataService;

    /**
     * @var LogManager
     */
    private LogManager $logManager;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private CartRepositoryInterface $quoteRepo;

    public function __construct(
        Http $request,
        Config $qliroConfig,
        Data $dataHelper,
        AjaxToken $ajaxToken,
        Session $checkoutSession,
        RecurringPaymentsDataService $recurringPaymentsDataService,
        LogManager $logManager,
        CartRepositoryInterface $quoteRepo
    ) {
        $this->request = $request;
        $this->dataHelper = $dataHelper;
        $this->ajaxToken = $ajaxToken;
        $this->qliroConfig = $qliroConfig;
        $this->checkoutSession = $checkoutSession;
        $this->recurringPaymentsDataService = $recurringPaymentsDataService;
        $this->logManager = $logManager;
        $this->quoteRepo = $quoteRepo;
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
                'AJAX:SET_RECURRING:ERROR_INACTIVE'
            );
        }

        $quote = $this->checkoutSession->getQuote();
        $this->logManager->setMerchantReferenceFromQuote($quote);
        $this->ajaxToken->setQuote($quote);

        if (!$this->ajaxToken->verifyToken($this->request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Security token is incorrect.')
                ],
                401,
                null,
                'AJAX:SET_RECURRING:ERROR_TOKEN'
            );
        }

        $data = $this->dataHelper->readPreparedPayload($this->request, 'AJAX:SET_RECURRING');
        $quote = $this->checkoutSession->getQuote();
        $paymentRecurringInfo = $this->recurringPaymentsDataService->quoteGetter($quote);
        $paymentRecurringInfo->setEnabled($data['isRecurring'] ?? false);
        $paymentRecurringInfo->setFrequencyOption($data['frequencyOption'] ?? '');
        $this->recurringPaymentsDataService->quoteSetter($quote, $paymentRecurringInfo);
        $this->quoteRepo->save($quote);

        return $this->dataHelper->sendPreparedPayload(
            ['status' => 'OK'],
            200,
            null,
            'AJAX:SET_RECURRING'
        );
    }
}

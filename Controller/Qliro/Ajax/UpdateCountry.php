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
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\CountrySelect;

/**
 * Update country method AJAX controller action class
 * Only used when country selector is enabled in config
 */
class UpdateCountry implements HttpPostActionInterface
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
     * @var LogManager
     */
    private LogManager $logManager;

    /**
     * @var CountrySelect
     */
    private CountrySelect $countrySelect;

    public function __construct(
        Http $request,
        Config $qliroConfig,
        Data $dataHelper,
        AjaxToken $ajaxToken,
        Session $checkoutSession,
        LogManager $logManager,
        CountrySelect $countrySelect
    ) {
        $this->request = $request;
        $this->dataHelper = $dataHelper;
        $this->ajaxToken = $ajaxToken;
        $this->qliroConfig = $qliroConfig;
        $this->checkoutSession = $checkoutSession;
        $this->logManager = $logManager;
        $this->countrySelect = $countrySelect;
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
                'AJAX:UPDATE_COUNTRY:ERROR_INACTIVE'
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
                'AJAX:UPDATE_COUNTRY:ERROR_TOKEN'
            );
        }

        $data = $this->dataHelper->readPreparedPayload($this->request, 'AJAX:UPDATE_COUNTRY');
        $countryId = (string)($data['countryId'] ?? '');
        $this->countrySelect->registerCountryChangeInput($countryId);

        return $this->dataHelper->sendPreparedPayload(
            ['status' => 'OK'],
            200,
            null,
            'AJAX:UPDATE_COUNTRY'
        );
    }
}

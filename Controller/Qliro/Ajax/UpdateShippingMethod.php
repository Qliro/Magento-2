<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ProductMetadata;
use Magento\Framework\App\ResponseInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Framework\App\ProductMetadataInterface;

/**
 * Update shipping method AJAX controller action class
 */
class UpdateShippingMethod extends \Magento\Framework\App\Action\Action
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
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * Inject dependnecies
     *
     * @param Context $context
     * @param Config $qliroConfig
     * @param Data $dataHelper
     * @param AjaxToken $ajaxToken
     * @param ManagementInterface $qliroManagement
     * @param Session $checkoutSession
     * @param Manager $logManager
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        Context $context,
        Config $qliroConfig,
        Data $dataHelper,
        AjaxToken $ajaxToken,
        ManagementInterface $qliroManagement,
        Session $checkoutSession,
        LogManager $logManager,
        ProductMetadataInterface $productMetadata
    ) {
        parent::__construct($context);
        $this->dataHelper = $dataHelper;
        $this->ajaxToken = $ajaxToken;
        $this->qliroConfig = $qliroConfig;
        $this->qliroManagement = $qliroManagement;
        $this->checkoutSession = $checkoutSession;
        $this->logManager = $logManager;
        $this->productMetadata = $productMetadata;
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
                'AJAX:UPDATE_SHIPPING_METHOD:ERROR_INACTIVE'
            );
        }

        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->getRequest();

        $quote = $this->checkoutSession->getQuote();
        $this->logManager->setMerchantReferenceFromQuote($quote);
        $this->ajaxToken->setQuote($quote);

        if (!$this->ajaxToken->verifyToken($request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Security token is incorrect.')
                ],
                401,
                null,
                'AJAX:UPDATE_SHIPPING_METHOD:ERROR_TOKEN'
            );
        }

        $data = $this->dataHelper->readPreparedPayload($request, 'AJAX:UPDATE_SHIPPING_METHOD');

        try {
            $secondaryOption = null;
            if ($this->qliroConfig->isUnifaunEnabled($quote->getStoreId())) {
                $shippingMethodCode = \Qliro\QliroOne\Model\Carrier\Unifaun::QLIRO_UNIFAUN_SHIPPING_CODE;
                $secondaryOption = $data['secondaryOption'] ?? null;
                $shippingPrice = $data['price'] ?? null;
                if ($this->productMetadata->getEdition() !== ProductMetadata::EDITION_NAME) {
                    $shippingPrice = $data['priceExVat'] ?? null;
                }
            } else if ($this->qliroConfig->isIngridEnabled($quote->getStoreId())) {
                $shippingMethodCode = \Qliro\QliroOne\Model\Carrier\Ingrid::QLIRO_INGRID_SHIPPING_CODE;
                $secondaryOption = $data['methodName'] ?? null;
                $shippingPrice = $data['price'] ?? null;
                if ($this->productMetadata->getEdition() !== ProductMetadata::EDITION_NAME) {
                    $shippingPrice = $data['priceExVat'] ?? null;
                }
            } else {
                $shippingMethodCode = $data['method'] ?? null;
                $shippingPrice = $data['price'] ?? null;
            }
            $result = $this->qliroManagement->setQuote($quote)->updateShippingMethod($shippingMethodCode, $secondaryOption, $shippingPrice);
        } catch (\Exception $exception) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Cannot update shipping method in quote.')
                ],
                400,
                null,
                'AJAX:UPDATE_SHIPPING_METHOD:ERROR'
            );
        }

        return $this->dataHelper->sendPreparedPayload(
            ['status' => $result ? 'OK' : 'SKIPPED'],
            200,
            null,
            'AJAX:UPDATE_SHIPPING_METHOD'
        );
    }
}

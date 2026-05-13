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
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Tax\Helper\Data as TaxHelper;

/**
 * Update shipping method AJAX controller action class
 */
class UpdateShippingMethod extends \Magento\Framework\App\Action\Action
{
    /**
     * Inject dependnecies
     *
     * @param Context $context
     * @param Config $qliroConfig
     * @param Data $dataHelper
     * @param AjaxToken $ajaxToken
     * @param ManagementInterface $qliroManagement
     * @param Session $checkoutSession
     * @param LogManager $logManager
     * @param ProductMetadataInterface $productMetadata
     * @param TaxHelper $taxHelper
     */
    public function __construct(
        Context $context,
        readonly private Config $qliroConfig,
        readonly private Data $dataHelper,
        readonly private AjaxToken $ajaxToken,
        readonly private ManagementInterface $qliroManagement,
        readonly private Session $checkoutSession,
        readonly private LogManager $logManager,
        readonly private ProductMetadataInterface $productMetadata,
        readonly private TaxHelper $taxHelper,
        readonly private LinkRepositoryInterface $linkRepository
    ) {
        parent::__construct($context);
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
        $this->logManager->debug('Starting to update shipping method for quote: ' . $quote->getId());
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

        try {
            $link = $this->linkRepository->getByQuoteId($quote->getId());
            if ($link->getIsLocked()) {
                return $this->dataHelper->sendPreparedPayload(
                    [
                        'status' => 'LOCKED',
                        'error' => (string)__('Shipping method cannot be updated after validation. The quote is locked.')
                    ],
                    423,
                    null,
                    'AJAX:UPDATE_SHIPPING_METHOD:LOCKED'
                );
            }
        } catch (NoSuchEntityException $e) {
            // No link found — allow the update to proceed
        }

        $this->logManager->debug('Starting to read prepared payload');
        $data = $this->dataHelper->readPreparedPayload($request, 'AJAX:UPDATE_SHIPPING_METHOD');
        $this->logManager->debug('Finished to read prepared payload');

        try {
            $secondaryOption = null;
            if ($this->qliroConfig->isUnifaunEnabled($quote->getStoreId())) {
                $this->logManager->debug('Unifaun enabled: ' . $quote->getId());
                $shippingMethodCode = \Qliro\QliroOne\Model\Carrier\Unifaun::QLIRO_UNIFAUN_SHIPPING_CODE;
                $secondaryOption = $data['secondaryOption'] ?? null;
                $shippingPrice = $data['price'] ?? null;
                if ($this->productMetadata->getEdition() !== ProductMetadata::EDITION_NAME
                    || $this->taxHelper->shippingPriceIncludesTax($quote->getStoreId()) === false)
                {
                    $shippingPrice = $data['priceExVat'] ?? null;
                }
            } else if ($this->qliroConfig->isIngridEnabled($quote->getStoreId())) {
                $this->logManager->debug('Ingrid enabled: ' . $quote->getId());
                $shippingMethodCode = \Qliro\QliroOne\Model\Carrier\Ingrid::QLIRO_INGRID_SHIPPING_CODE;
                $secondaryOption = $data['methodName'] ?? null;
                $shippingPrice = $data['price'] ?? null;
                if ($this->productMetadata->getEdition() !== ProductMetadata::EDITION_NAME
                    || $this->taxHelper->shippingPriceIncludesTax($quote->getStoreId()) === false)
                {
                    $shippingPrice = $data['priceExVat'] ?? null;
                }
            } else {
                $shippingMethodCode = $data['method'] ?? null;
                $shippingPrice = $data['price'] ?? null;
            }
            $result = $this->qliroManagement->setQuote($quote)->updateShippingMethod($shippingMethodCode, $secondaryOption, $shippingPrice);
        } catch (\Exception $exception) {
            $this->logManager->debug('Failed to update shipping method in quote: ' .
                $quote->getId() . ' error: ' . $exception->getMessage()
            );
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

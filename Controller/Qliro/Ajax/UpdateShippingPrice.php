<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ProductMetadata;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\QuoteValidatedException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Tax\Helper\Data as TaxHelper;

/**
 * Update shipping method options AJAX controller action class
 */
class UpdateShippingPrice extends \Magento\Framework\App\Action\Action
{
    /**
     * @var Quote|CartInterface
     */
    private $quote;

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
     * @param TaxHelper $taxHelper
     */
    public function __construct(
        Context $context,
        readonly private Config $qliroConfig,
        readonly private Data $dataHelper,
        readonly private AjaxToken $ajaxToken,
        readonly private ManagementInterface $qliroManagement,
        readonly private Session $checkoutSession,
        readonly private Manager $logManager,
        readonly private ProductMetadataInterface $productMetadata,
        readonly private TaxHelper $taxHelper,
        readonly private LinkRepositoryInterface $linkRepository
    ) {
        parent::__construct($context);
    }

    /**
     * Dispatch the action
     *
     * @return ResultInterface|ResponseInterface
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
                'AJAX:UPDATE_SHIPPING_PRICE:ERROR_INACTIVE'
            );
        }

        /** @var Http $request */
        $request = $this->getRequest();

        try {
            $quote = $this->getQuote();
        } catch (NoSuchEntityException|LocalizedException $e) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__("Quote does not exist.")
                ],
                403,
                null,
                'AJAX:UPDATE_SHIPPING_PRICE:ERROR_QUOTE_NOT_FOUND'
            );
        }

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
                'AJAX:UPDATE_SHIPPING_PRICE:ERROR_TOKEN'
            );
        }

        // Read once, because reading it twice logs the body twice, and inside a guard, because
        // anything raised while reading it used to leave the controller as Magento's error page
        // in place of the JSON the widget's own error handler expects
        try {
            $shippingPrice = $this->getShippingPrice();
        } catch (\Exception $exception) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Cannot update shipping method option in quote.')
                ],
                400,
                null,
                'AJAX:UPDATE_SHIPPING_PRICE:ERROR'
            );
        }


        try {
            /*
             * Only after Qliro validated the order, not while the payment is merely in progress:
             * Qliro sends its final delivery choice during identity verification, and refusing it
             * left the quote without a shipping method to validate against. The refusal itself is
             * made where the write is, because whether this payload writes anything is only known
             * once the store's own observers have seen it, and refusing beforehand put an error
             * dialog in front of a buyer over a call that changed nothing.
             */
            $result = $this->qliroManagement->setQuote($quote)->updateShippingPrice($shippingPrice);
        } catch (QuoteValidatedException $exception) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'LOCKED',
                    'error' => $exception->getMessage(),
                ],
                423,
                null,
                'AJAX:UPDATE_SHIPPING_PRICE:LOCKED'
            );
        } catch (\Exception $exception) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__('Cannot update shipping method option in quote.')
                ],
                400,
                null,
                'AJAX:UPDATE_SHIPPING_PRICE:ERROR'
            );
        }

        return $this->dataHelper->sendPreparedPayload(
            ['status' => $result ? 'OK' : 'SKIPPED'],
            200,
            null,
            'AJAX:UPDATE_SHIPPING_PRICE'
        );
    }

    /**
     * Retrieve the current quote from the checkout session.
     *
     * @return Quote The current quote object.
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getQuote(): Quote
    {
        if (!$this->quote) {
            $this->quote = $this->checkoutSession->getQuote();
        }
        return $this->quote;
    }

    /**
     * Calculate and retrieve the shipping price from the request payload.
     * Adjusts the shipping price to exclude/including tax if certain conditions are met.
     *
     * @return float|null The calculated shipping price, excluding tax if applicable, null when the
     *                    payload carries none
     */
    protected function getShippingPrice(): ?float
    {
        /** @var Http $request */
        $request = $this->getRequest();

        $data = $this->dataHelper->readPreparedPayload($request, 'AJAX:UPDATE_SHIPPING_PRICE');
        $shippingPrice = $data['price'] ?? ($data['newShippingPrice'] ?? null);

        // A payload with no price at all is a bad request, not a price of zero, and the declared
        // float made it a TypeError instead
        if ($shippingPrice === null) {
            return null;
        }

        $shippingPrice = (float)$shippingPrice;
        $taxPercentage = $this->getTaxPercentage();

        if (!$shippingPrice || $taxPercentage <= 0) {
            return $shippingPrice;
        }

        if ($this->isTaxReduceAllowed()) {
            return $shippingPrice / (1 + ($taxPercentage / 100));
        }

        return $shippingPrice;
    }

    /**
     * Determines whether tax reduction is allowed based on the store's shipping price tax configuration.
     *
     * @return bool True if tax reduction is allowed, false otherwise.
     */
    private function isTaxReduceAllowed(): bool
    {
        try {
            $quote = $this->getQuote();
        } catch (NoSuchEntityException|LocalizedException $e) {
            return false;
        }

        if ($this->taxHelper->shippingPriceIncludesTax($this->getQuote()->getStore()) === false
            && ($this->qliroConfig->isUnifaunEnabled($quote->getStoreId())
                || $this->qliroConfig->isIngridEnabled($quote->getStoreId()))
        ) {
            return true;
        }

        if ($this->productMetadata->getEdition() !== ProductMetadata::EDITION_NAME
            && ($this->qliroConfig->isUnifaunEnabled($quote->getStoreId())
                || $this->qliroConfig->isIngridEnabled($quote->getStoreId()))
        ) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve the tax percentage applied to the shipping address in the current quote.
     *
     * @return float The tax percentage applied to the shipping address, or 0.0 if no taxes are applied.
     */
    private function getTaxPercentage(): float
    {
        $taxPercentage = 0.0;

        try {
            $taxes = $this->getQuote()->getShippingAddress()->getAppliedTaxes();
        } catch (NoSuchEntityException|LocalizedException $e) {
            return $taxPercentage;
        }
        if (is_array($taxes) && count($taxes) > 0) {
            $taxRule = current($taxes);
            $taxPercentage = (float)$taxRule['percent'];
        }

        return $taxPercentage;
    }
}

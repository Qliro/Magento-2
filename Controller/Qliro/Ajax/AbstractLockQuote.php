<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use \Magento\Framework\App\Action\HttpPostActionInterface;
use \Magento\Framework\App\CsrfAwareActionInterface;
use \Magento\Framework\App\Request\InvalidRequestException;
use \Magento\Framework\App\RequestInterface;
use \Magento\Framework\Exception\AlreadyExistsException;
use \Magento\Framework\Exception\NoSuchEntityException;
use \Magento\Quote\Api\Data\CartInterface;
use \Qliro\QliroOne\Model\Config;
use \Qliro\QliroOne\Helper\Data;
use \Magento\Framework\App\Request\Http;
use \Qliro\QliroOne\Model\Security\AjaxToken;
use \Qliro\QliroOne\Model\Logger\Manager;
use \Magento\Quote\Api\CartRepositoryInterface;
use \Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use \Qliro\QliroOne\Api\LinkRepositoryInterface;

abstract class AbstractLockQuote implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Contains logger mask prefix
     *
     * @var string
     */
    protected string $loggerMaskPrefix = '';

    /**
     * @param Config $qliroConfig
     * @param Data $dataHelper
     * @param Http $request
     * @param AjaxToken $ajaxToken
     * @param Manager $logManager
     * @param CartRepositoryInterface $cartRepository
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     * @param LinkRepositoryInterface $linkRepository
     */
    public function __construct(
        protected readonly Config $qliroConfig,
        protected readonly Data $dataHelper,
        protected readonly Http $request,
        protected readonly AjaxToken $ajaxToken,
        protected readonly Manager $logManager,
        protected readonly CartRepositoryInterface $cartRepository,
        protected readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        protected readonly LinkRepositoryInterface $linkRepository
    )
    {

    }

    /**
     * @inheritDoc
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
                $this->loggerMaskPrefix . 'ERROR_INACTIVE'
            );
        }

        try {
            $quote = $this->getQuote();
        } catch (NoSuchEntityException $e) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__($e->getMessage())
                ],
                403,
                null,
                $this->loggerMaskPrefix . 'NOT_FOUND'
            );
        }

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
                $this->loggerMaskPrefix . 'ERROR_TOKEN'
            );
        }


        try {
            $this->actionExecute((int)$quote->getId());
        } catch (NoSuchEntityException|AlreadyExistsException $e) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    'status' => 'FAILED',
                    'error' => (string)__($e->getMessage())
                ],
                401,
                null,
                $this->loggerMaskPrefix . 'ERROR_LOCKING'
            );
        }


        return $this->dataHelper->sendPreparedPayload(
            ['status' => 'OK'],
            200,
            null,
            $this->loggerMaskPrefix . 'SUCCESS'
        );
    }

    /**
     *
     * Perform locking operation: lock or unlock
     *
     * @param int $quoteId
     * @return bool
     * @throws NoSuchEntityException
     * @throws AlreadyExistsException
     */
    abstract protected function actionExecute(int $quoteId) : bool;

    /**
     * Retrieves the cart quote based on the provided quote identifier.
     * The quote identifier can be either a numeric ID or a masked quote ID.
     *
     * @return CartInterface The cart quote associated with the provided quote ID.
     * @throws NoSuchEntityException If no quote ID is provided or if the quote cannot be retrieved.
     */
    protected function getQuote(): CartInterface
    {
        $data = $this->dataHelper->readPreparedPayload($this->request, 'AJAX:LOCK_QUOTE');
        $rawId = is_array($data) && isset($data['quoteId']) ? $data['quoteId'] : null;
        if (!$rawId) {
            throw new NoSuchEntityException(__('Quote Id is not found'));
        }

        if (ctype_digit($rawId)) {
            $quoteId = (int)$rawId;
        } else {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($rawId);
        }

        return $this->cartRepository->get($quoteId);
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * Hand the Qliro order html snippet to the payment method renderer.
 *
 * Only the iframe render mode calls this. The standalone checkout page still receives its snippet
 * with the page, through Plugin\Block\Checkout\LayoutProcessorPlugin, because that page is nothing
 * but the snippet. Keeping this off the native checkout page is the point: a Qliro order is created
 * when the buyer chooses Qliro, not for everyone who reaches the payment step.
 */
class GetSnippet extends \Magento\Framework\App\Action\Action
{
    /**
     * Inject dependencies
     *
     * @param Context $context
     * @param Config $qliroConfig
     * @param Data $dataHelper
     * @param AjaxToken $ajaxToken
     * @param ManagementInterface $qliroManagement
     * @param Session $checkoutSession
     * @param Manager $logManager
     * @param LinkRepositoryInterface $linkRepository
     */
    public function __construct(
        Context $context,
        private readonly Config $qliroConfig,
        private readonly Data $dataHelper,
        private readonly AjaxToken $ajaxToken,
        private readonly ManagementInterface $qliroManagement,
        private readonly Session $checkoutSession,
        private readonly Manager $logManager,
        private readonly LinkRepositoryInterface $linkRepository
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
                ['error' => (string)__('Qliro One is not active.')],
                403,
                null,
                'AJAX:GET_SNIPPET:ERROR_INACTIVE'
            );
        }

        $quote = $this->checkoutSession->getQuote();
        $this->logManager->setMerchantReferenceFromQuote($quote);
        $this->ajaxToken->setQuote($quote);

        if (!$this->ajaxToken->verifyToken($this->getRequest()->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => (string)__('Security token is incorrect.')],
                401,
                null,
                'AJAX:GET_SNIPPET:ERROR_TOKEN'
            );
        }

        try {
            /*
             * The widget locks the link when the buyer starts paying and unlocks it when that ends.
             * A buyer who walks away in between leaves it locked, and in the redirect mode opening
             * the Qliro page cleared it. Nothing else would here, and a locked link refuses every
             * later change to the cart.
             */
            $this->linkRepository->unlock((int)$quote->getId());
        } catch (NoSuchEntityException $exception) {
            // No link for this quote yet, so there is nothing to unlock
        }

        try {
            $snippet = (string)$this->qliroManagement->setQuote($quote)->getQliroOrder()->getOrderHtmlSnippet();
        } catch (AlreadyPlacedException $exception) {
            /*
             * The buyer has paid and come back to the checkout, with the Back button or a reopened
             * tab. There is a Magento order on the way, so the pending page is where they belong,
             * and it is the standalone flow's answer to this too. Retrying here would only fail.
             */
            $this->logManager->debug('Order already placed, sending the buyer to the pending page.');

            return $this->dataHelper->sendPreparedPayload(
                ['redirect' => $quote->getStore()->getUrl('checkout/qliro/pending')],
                200,
                null,
                'AJAX:GET_SNIPPET:ALREADY_PLACED'
            );
        } catch (\Exception $exception) {
            // The message can carry the API response, so it goes to the log and not to the browser
            $this->logManager->critical($exception, ['extra' => ['quote_id' => $quote->getId()]]);
            $snippet = '';
        }

        if ($snippet === '') {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => (string)__('Qliro checkout could not be loaded. Please try again.')],
                400,
                null,
                'AJAX:GET_SNIPPET:ERROR'
            );
        }

        return $this->dataHelper->sendPreparedPayload(
            ['snippet' => $snippet],
            200,
            null,
            'AJAX:GET_SNIPPET'
        );
    }
}

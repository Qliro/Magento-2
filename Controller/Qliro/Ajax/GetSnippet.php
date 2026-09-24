<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
use Qliro\QliroOne\Model\Exception\UnsupportedQuoteException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Management\HtmlSnippet;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * Hand the Qliro order html snippet to the payment method renderer.
 *
 * Only the iframe render mode calls this. The standalone checkout page still receives its snippet
 * with the page, through Plugin\Block\Checkout\LayoutProcessorPlugin, because that page is nothing
 * but the snippet. Keeping this off the native checkout page is the point: a Qliro order is created
 * when the buyer chooses Qliro, not for everyone who reaches the payment step.
 */
class GetSnippet implements HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private Http $request;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\App\Request\Http $request
     * @param Config $qliroConfig
     * @param Data $dataHelper
     * @param AjaxToken $ajaxToken
     * @param HtmlSnippet $htmlSnippet
     * @param Session $checkoutSession
     * @param Manager $logManager
     */
    public function __construct(
        Http $request,
        private readonly Config $qliroConfig,
        private readonly Data $dataHelper,
        private readonly AjaxToken $ajaxToken,
        private readonly HtmlSnippet $htmlSnippet,
        private readonly Session $checkoutSession,
        private readonly Manager $logManager
    ) {
        $this->request = $request;
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

        if (!$this->ajaxToken->verifyToken($this->request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => (string)__('Security token is incorrect.')],
                401,
                null,
                'AJAX:GET_SNIPPET:ERROR_TOKEN'
            );
        }

        try {
            // The same fetch the checkout page makes, including the unlock of a link the buyer
            // left locked by walking out of the widget mid payment
            $snippet = $this->htmlSnippet->setQuote($quote)->fetch();
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
        } catch (UnsupportedQuoteException $exception) {
            /*
             * A cart this payment method cannot take, a fractional quantity today. The buyer can
             * only change a cart they are told about, so this message reaches the panel as it
             * stands, the way the checkout page shows it in place of the widget.
             */
            $this->logManager->debug('The cart cannot be paid for with Qliro: ' . $exception->getMessage());

            return $this->dataHelper->sendPreparedPayload(
                ['error' => $exception->getMessage()],
                400,
                null,
                'AJAX:GET_SNIPPET:UNSUPPORTED_QUOTE'
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

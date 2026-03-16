<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Qliro\QliroOne\Api\Checkout\OrderServiceInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * Base class for frontend AJAX actions called from the Qliro checkout iframe.
 *
 * Security: every request is validated against the AjaxToken that was baked
 * into the checkout page config (securityToken). The token encodes the quote ID,
 * so it is quote-specific and expires after 2 hours.
 */
abstract class AbstractAjaxAction implements HttpPostActionInterface
{
    public function __construct(
        protected readonly HttpRequest           $request,
        protected readonly OrderServiceInterface $orderService,
        protected readonly CheckoutSession       $checkoutSession,
        protected readonly AjaxToken             $ajaxToken,
        protected readonly JsonFactory           $jsonFactory,
        protected readonly Config                $qliroConfig,
        protected readonly LogManager            $logManager
    ) {
    }

    /**
     * Verify that Qliro is active and the request carries a valid session token.
     */
    protected function verifyRequest(): bool
    {
        if (!$this->qliroConfig->isActive()) {
            return false;
        }

        $token = $this->request->getParam('token');
        $quote = $this->checkoutSession->getQuote();

        return $this->ajaxToken->setQuote($quote)->verifyToken($token);
    }

    /**
     * Decode the raw JSON request body.
     */
    protected function getBody(): array
    {
        return json_decode($this->request->getContent(), true) ?? [];
    }

    /**
     * Build a JSON success response.
     */
    protected function jsonResponse(array $data, int $httpCode = 200): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($httpCode);
        $result->setData($data);
        return $result;
    }

    /**
     * Build a JSON error response.
     */
    protected function errorResponse(string $message, int $httpCode = 400): Json
    {
        return $this->jsonResponse(['error' => $message], $httpCode);
    }
}

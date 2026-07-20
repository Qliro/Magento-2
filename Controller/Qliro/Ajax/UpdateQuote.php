<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\Controller\ResultInterface;

/**
 * Called by the Magento checkout JS (qliro.js → updateCart) when the Magento
 * cart changes (coupon applied, qty changed, etc.). Fetches the current Qliro
 * order so any in-flight update is processed, then returns Magento's grand
 * total so the JS can verify that Qliro's iframe total now matches.
 *
 * Response shape expected by qliro.js:
 * { "order": { "totalPrice": 123.45 } }
 *
 * URL: checkout/qliro_ajax/updateQuote
 */
class UpdateQuote extends AbstractAjaxAction
{
    public function execute(): ResultInterface
    {
        if (!$this->verifyRequest()) {
            return $this->errorResponse('Unauthorized', 401);
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $quote->collectTotals();
            $this->orderService->pushQuoteUpdate();

            $shippingInclTax = $quote->isVirtual()
                ? 0.0
                : (float) $quote->getShippingAddress()->getShippingInclTax();
            $totalPrice = round((float) $quote->getGrandTotal() - $shippingInclTax, 2);
        } catch (\Exception $e) {
            $this->logManager->critical($e);
            return $this->errorResponse($e->getMessage());
        }

        return $this->jsonResponse([
            'order' => ['totalPrice' => $totalPrice],
        ]);
    }
}

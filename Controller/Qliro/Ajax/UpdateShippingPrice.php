<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\Controller\ResultInterface;

/**
 * Called when Qliro (Unifaun/Ingrid) reports a shipping price change.
 *
 * Expected body (from Qliro's onShippingPriceChanged event):
 * { "newShippingPrice": 56.25 }
 *
 * URL: checkout/qliro_ajax/updateShippingPrice
 */
class UpdateShippingPrice extends AbstractAjaxAction
{
    public function execute(): ResultInterface
    {
        if (!$this->verifyRequest()) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $data  = $this->getBody();
        $price = isset($data['newShippingPrice']) ? (float) $data['newShippingPrice'] : null;

        try {
            $result = $this->orderService->updateShippingPrice($price);
        } catch (\Exception $e) {
            $this->logManager->critical($e);
            return $this->errorResponse($e->getMessage());
        }

        return $this->jsonResponse(['success' => $result]);
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\Controller\ResultInterface;

/**
 * Called by the Qliro iframe when the customer selects a shipping method.
 *
 * Expected body (from Qliro's onShippingMethodChanged event):
 * {
 *   "MerchantReference": "flatrate_flatrate",
 *   "SecondaryOption": null,
 *   "PriceIncVat": 10.0
 * }
 *
 * URL: checkout/qliro_ajax/updateShippingMethod
 */
class UpdateShippingMethod extends AbstractAjaxAction
{
    public function execute(): ResultInterface
    {
        if (!$this->verifyRequest()) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $data = $this->getBody();

        // Qliro JS SDK field names differ from the REST API field names:
        //   method          → shipping method code (= MerchantReference)
        //   price           → price incl. VAT
        //   secondaryOption → secondary option string (e.g. Ingrid access code)
        $code            = (string) ($data['MerchantReference'] ?? $data['method'] ?? '');
        $secondaryOption = isset($data['SecondaryOption']) ? (string) $data['SecondaryOption'] : (isset($data['secondaryOption']) ? (string) $data['secondaryOption'] : null);
        $price           = isset($data['PriceIncVat']) ? (float) $data['PriceIncVat'] : (isset($data['price']) ? (float) $data['price'] : null);

        if (empty($code)) {
            return $this->errorResponse('Shipping method code is required');
        }

        try {
            $result = $this->orderService->updateShippingMethod($code, $secondaryOption, $price);
        } catch (\Exception $e) {
            $this->logManager->critical($e);
            return $this->errorResponse($e->getMessage());
        }

        return $this->jsonResponse(['success' => $result]);
    }
}

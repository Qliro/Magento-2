<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\Controller\ResultInterface;

/**
 * Called when the customer changes the payment method in the Qliro iframe.
 *
 * Some payment methods carry a service fee (e.g. invoice fee). This
 * triggers a quote recalculation so Magento totals stay in sync.
 *
 * Expected body (from Qliro's onPaymentMethodChanged event):
 * {
 *   "PaymentTypeCode": "Invoice",
 *   "PaymentMethodName": "Qliro Invoice",
 *   "PaymentFeeIncVat": 29.0   // optional
 * }
 *
 * URL: checkout/qliro_ajax/updatePaymentMethod
 */
class UpdatePaymentMethod extends AbstractAjaxAction
{
    public function execute(): ResultInterface
    {
        if (!$this->verifyRequest()) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $data = $this->getBody();
        $fee  = (float) ($data['PaymentFeeIncVat'] ?? 0.0);

        try {
            $result = $this->orderService->updateFee($fee);
        } catch (\Exception $e) {
            $this->logManager->critical($e);
            return $this->errorResponse($e->getMessage());
        }

        return $this->jsonResponse(['success' => $result]);
    }
}

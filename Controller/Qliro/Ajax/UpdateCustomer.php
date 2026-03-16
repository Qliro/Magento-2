<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\Controller\ResultInterface;

/**
 * Called by the Qliro iframe when the customer fills in their personal data
 * (email, SSN / personal number, name, address).
 *
 * Applies the customer data to the Magento quote and triggers a quote
 * recalculation + Qliro order update so that shipping methods appear.
 *
 * URL: checkout/qliro_ajax/updateCustomer
 */
class UpdateCustomer extends AbstractAjaxAction
{
    public function execute(): ResultInterface
    {
        if (!$this->verifyRequest()) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $customerData = $this->getBody();

        try {
            $this->orderService->updateCustomer($customerData);
        } catch (\Exception $e) {
            $this->logManager->critical($e);
            return $this->errorResponse($e->getMessage());
        }

        return $this->jsonResponse(['success' => true]);
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

use Magento\Framework\Controller\ResultInterface;

/**
 * Class GetSnippet
 *
 */
class GetSnippet extends AbstractAjaxAction
{
    public function execute(): ResultInterface
    {
        if (!$this->verifyRequest()) {
            return $this->errorResponse('Unauthorized', 401);
        }

        try {
            $qliroOrder = $this->orderService->getQliroOrder();
            $snippet = $qliroOrder['OrderHtmlSnippet'] ?? '';

            if ($snippet === '') {
                return $this->errorResponse('Qliro checkout is currently unavailable.');
            }
        } catch (\Exception $e) {
            $this->logManager->critical($e);
            return $this->errorResponse($e->getMessage());
        }

        return $this->jsonResponse(['snippet' => $snippet]);
    }
}

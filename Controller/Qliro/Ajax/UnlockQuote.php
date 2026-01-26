<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

class UnlockQuote extends AbstractLockQuote
{
    /**
     * @ingeritDoc
     */
    protected string $loggerMaskPrefix = 'AJAX:UNLOCK_QUOTE:';

    /**
     * @inheritDoc
     */
    protected function actionExecute(int $quoteId): bool
    {
        $this->linkRepository->unlock($quoteId);

        return true;
    }
}

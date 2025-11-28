<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Ajax;

class LockQuote extends AbstractLockQuote
{
    /**
     * @ingeritDoc
     */
    protected string $loggerMaskPrefix = 'AJAX:LOCK_QUOTE:';

    /**
     * @inheritDoc
     */
    protected function actionExecute(int $quoteId): bool
    {
        $this->linkRepository->lock($quoteId);

        return true;
    }
}

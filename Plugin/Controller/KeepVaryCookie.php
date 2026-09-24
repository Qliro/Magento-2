<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Controller;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\Forward;

/**
 * Leaves the buyer's page cache vary cookie alone on the module's own storefront controllers
 *
 * Magento puts the store and the currency into the vary string from a plugin on the deprecated
 * AbstractAction only. A controller on the action interfaces rewrites the cookie without them, and
 * the next cached page reaches a buyer in a second currency priced in the default one.
 */
class KeepVaryCookie
{
    /**
     * @param HttpResponse $response
     */
    public function __construct(
        private readonly HttpResponse $response
    ) {
    }

    /**
     * Mark the response so the page cache neither rewrites nor deletes the vary cookie
     *
     * @param ActionInterface $subject
     * @param mixed $result
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(ActionInterface $subject, $result)
    {
        // A forward is rendered by another controller, which sets the vary cookie itself
        if (!$result instanceof Forward) {
            $this->response->setMetadata('NotCacheable', true);
        }

        return $result;
    }
}

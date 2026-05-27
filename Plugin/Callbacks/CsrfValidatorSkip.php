<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Callbacks;

class CsrfValidatorSkip
{
    /**
     * @param \Magento\Framework\App\Request\CsrfValidator $subject
     * @param \Closure $proceed
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\App\ActionInterface $action
     */
    public function aroundValidate(
        \Magento\Framework\App\Request\CsrfValidator $subject,
        \Closure $proceed,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\App\ActionInterface $action
    ): void {
        if ($request->getModuleName() == 'checkout' &&
            $request->getControllerModule() == 'Qliro_QliroOne' &&
            $request->getControllerName() == 'qliro_callback') {
            return; // Callbacks must ignore formKey
        }
        $proceed($request, $action);
    }
}

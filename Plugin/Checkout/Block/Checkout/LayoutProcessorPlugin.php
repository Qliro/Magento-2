<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Checkout\Block\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Qliro\QliroOne\Api\Checkout\OrderServiceInterface;

/**
 * Checkout Layout Processor plugin class
 */
class LayoutProcessorPlugin
{
    /**
     * Class constructor
     *
     * @param OrderServiceInterface $qliroOrderService
     */
    public function __construct(
        protected OrderServiceInterface $qliroOrderService
    ) {
    }

    /**
     * Alter the checkout configuration array to add binds for QliroOne OnePage checkout
     *
     * @param LayoutProcessor $subject
     * @param array $result
     * @return array
     * @noinspection PhpUnusedParameterInspection
     */
    public function afterProcess(LayoutProcessor $subject, array $result): array
    {
        if (isset($result['components']['checkout']['children']['steps']['children']['qliroone-step'])) {
            $qliroOrder = $this->qliroOrderService->getQliroOrder();
            $result['components']['checkout']['children']['steps']['children']['qliroone-step']['html_snippet'] = $qliroOrder['OrderHtmlSnippet'] ?? '';
        }
        if (isset($result['components']['checkout']['children']['sidebar']['children']['summary']['children']['totals'])) {
            unset($result['components']['checkout']['children']['sidebar']['children']['summary']['children']['totals']);
        }

        return $result;
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Checkout;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Success\Session as SuccessSession;

/**
 * QliroOne checkout success page main block class
 */
class Success extends Template
{
    /**
     * Class constructor
     *
     * @param Context $context
     * @param Config $qliroConfig
     * @param SuccessSession $successSession
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Config $qliroConfig,
        private readonly SuccessSession $successSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get Id of placed order
     *
     * @return string|null
     */
    public function getIncrementId(): ?string
    {
        return $this->successSession->getSuccessIncrementId();
    }

    /**
     * Get the Qliro order confirmation HTML snippet, if available.
     *
     * @return string|null
     */
    public function getSuccessHtmlSnippet(): ?string
    {
        return $this->successSession->getSuccessHtmlSnippet();
    }

    /**
     * Check if debug mode is on
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->qliroConfig->isDebugMode();
    }
}

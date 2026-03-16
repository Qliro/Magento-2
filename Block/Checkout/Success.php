<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

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
     * Get QliroOne final HTML snippet
     *
     * @return string
     */
    public function getHtmlSnippet()
    {
        return $this->successSession->getSuccessHtmlSnippet();
    }

    /**
     * Get Id of placed order
     *
     * @return string
     */
    public function getIncrementId()
    {
        return $this->successSession->getSuccessIncrementId();
    }

    /**
     * Check if debug mode is on
     *
     * @return bool
     */
    public function isDebug()
    {
        return $this->qliroConfig->isDebugMode();
    }
}

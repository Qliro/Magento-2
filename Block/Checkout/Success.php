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
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var SuccessSession
     */
    private $successSession;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $qliroConfig,
        SuccessSession $successSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->qliroConfig = $qliroConfig;
        $this->successSession = $successSession;
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
     * Get the entity id of the placed order
     *
     * @return int
     */
    public function getOrderId()
    {
        return (int)$this->successSession->getSuccessOrderId();
    }

    /**
     * Get the URL the page reports to once a buyer has seen it
     *
     * @return string
     */
    public function getDisplayedUrl()
    {
        return $this->getUrl('checkout/qliro_ajax/successDisplayed');
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

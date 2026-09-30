<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Block\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Checkout\Model\Session;
use Qliro\QliroOne\Api\ManagementInterface;

/**
 * Checkout Layout Processor plugin class
 */
class LayoutProcessorPlugin
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * @var \Qliro\QliroOne\Api\ManagementInterface
     */
    private $qliroManagement;

    /**
     * Inject dependencies
     *
     * @param Session $session
     * @param \Qliro\QliroOne\Api\ManagementInterface $qliroManagement
     */
    public function __construct(
        Session $session,
        ManagementInterface $qliroManagement
    ) {
        $this->quote = $session->getQuote();
        $this->qliroManagement = $qliroManagement;
    }

    /**
     * Alter the checkout configuration array to add binds for QliroOne OnePage checkout
     *
     * @param \Magento\Checkout\Block\Checkout\LayoutProcessor $subject
     * @param array $result
     * @return array
     */
    public function afterProcess(LayoutProcessor $subject, $result)
    {
        if (isset($result['components']['checkout']['children']['steps']['children']['qliroone-step'])) {
            $htmlSnippet = $this->generateHtmlSnippet();
            $result['components']['checkout']['children']['steps']['children']['qliroone-step']['html_snippet'] =
                $htmlSnippet;
        }

        return $result;
    }

    /**
     * Returns iframe snippet of checkout form
     *
     * @return string
     */
    private function generateHtmlSnippet()
    {
        return (string)$this->qliroManagement->setQuote($this->quote)->getHtmlSnippet();
    }
}

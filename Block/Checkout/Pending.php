<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Checkout;

use Magento\Checkout\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Quote\Agent;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * QliroOne checkout Pending page main block class
 */
class Pending extends Template
{
    /**
     * @var \Qliro\QliroOne\Api\ManagementInterface
     */
    private $qliroManagement;

    /**
     * @var \Qliro\QliroOne\Model\Security\AjaxToken
     */
    private $ajaxToken;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Qliro\QliroOne\Model\Quote\Agent
     */
    private $quoteAgent;

    /**
     * @var LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Qliro\QliroOne\Api\ManagementInterface $qliroManagement
     * @param \Qliro\QliroOne\Model\Security\AjaxToken $ajaxToken
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Model\Quote\Agent $quoteAgent
     * @param \Qliro\QliroOne\Api\LinkRepositoryInterface $linkRepository
     * @param array $data
     */
    public function __construct(
        Context $context,
        ManagementInterface $qliroManagement,
        AjaxToken $ajaxToken,
        Config $qliroConfig,
        Agent $quoteAgent,
        LinkRepositoryInterface $linkRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->qliroManagement = $qliroManagement;
        $this->ajaxToken = $ajaxToken;
        $this->qliroConfig = $qliroConfig;
        $this->quoteAgent = $quoteAgent;
        $this->storeManager = $context->getStoreManager();
        $this->linkRepository = $linkRepository;
    }

    /**
     * Get QliroOne final HTML snippet
     *
     * Probably not used...
     *
     * @return string
     */
    public function getHtmlSnippet()
    {
        $quote = $this->quoteAgent->fetchRelevantQuote();

        return $quote ? $this->qliroManagement->setQuote($quote)->getHtmlSnippet() : null;
    }

    /**
     * Get a URL for the polling script
     *
     * @return string
     */
    public function getPollPendingUrl()
    {
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();

        $quote = $this->quoteAgent->fetchRelevantQuote();

        if ($quote) {
            try {
                // To update link PlacedAt, if this throws an exception, checkoutStatus will still respond inside an hour...
                $link = $this->linkRepository->getByQuoteId($quote->getId());
                $link->setPlacedAt(time());
                $this->linkRepository->save($link);
            } catch (\Exception $exception) {
                // Do nothing
            }
            $params = [
                '_query' => [
                    'token' => $this->ajaxToken->setQuote($quote)->getToken(),
                ]
            ];
        } else {
            $params = [];
        }

        return $store->getUrl('checkout/qliro_ajax/pollPending', $params);
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

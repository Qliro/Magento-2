<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;

/**
 * When a customer logs in, everything around the quote changes, so we need to unlink the quote with qliro
 */
class CustomerLogin implements ObserverInterface
{
    /**
     * @var LinkRepositoryInterface
     */
    private $linkRepository;
    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * Inject dependencies
     * @param LinkRepositoryInterface $linkRepository
     * @param Session $checkoutSession
     */
    public function __construct(
        LinkRepositoryInterface $linkRepository,
        Session $checkoutSession
    ) {
        $this->linkRepository = $linkRepository;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $link = $this->linkRepository->getByQuoteId($this->getQuote()->getId());
            $link->setIsActive(false);
            $link->setMessage('Unlinking quote due to customer login');
            $this->linkRepository->save($link);
        } catch (\Exception $exception) {
        }
    }

    /**
     * Get current quote from checkout session
     *
     * @return \Magento\Quote\Model\Quote
     */
    private function getQuote()
    {
        return $this->checkoutSession->getQuote();
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * When a customer logs in, everything around the quote changes, so we need to unlink the quote with qliro
 */
class CustomerLogin implements ObserverInterface
{
    /**
     * Class constructor
     *
     * @param LinkRepositoryInterface $linkRepository
     * @param Session $checkoutSession
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly Session $checkoutSession,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * @param Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer): void
    {
        try {
            $link = $this->linkRepository->getByQuoteId($this->getQuote()->getId());
            $link->setIsActive(false);
            $link->setMessage('Unlinking quote due to customer login');
            $this->linkRepository->save($link);
        } catch (NoSuchEntityException $exception) {
            // No Qliro link for this quote — nothing to unlink. Expected for most logins.
        } catch (\Exception $exception) {
            $this->logManager->debug($exception);
        }
    }

    /**
     * Get current quote from checkout session
     *
     * @return \Magento\Quote\Model\Quote
     */
    private function getQuote(): \Magento\Quote\Model\Quote
    {
        return $this->checkoutSession->getQuote();
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Quote;

use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * Quote Agent class for temporarily persist quote for post order creation phase
 */
class Agent
{
    const COOKIE_NAME = 'QOMR';  // Stands for "Qliro One Merchant Reference"
    const COOKIE_LIFETIME = 10800; // 3 hours for now

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $relevantQuote;

    /**
     * Class constructor
     *
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory
     * @param \Qliro\QliroOne\Api\LinkRepositoryInterface $linkRepository
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
    public function __construct(
        private readonly Session $checkoutSession,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly Manager $logManager
    ) {
    }

    /**
     * Store specific quote in a Quote cookie agent
     *
     * @param \Magento\Quote\Model\Quote $quote
     */
    public function store(Quote $quote)
    {
        $this->logManager->addTag('cookies');

        try {
            $link = $this->linkRepository->getByQuoteId($quote->getId());
            $merchantReference = $link->getReference();
            $this->logManager->setMerchantReference($merchantReference);

            $cookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
            $cookieMetadata->setDuration(self::COOKIE_LIFETIME);
            $cookieMetadata->setPath('/');

            $this->cookieManager->setPublicCookie(self::COOKIE_NAME, $merchantReference, $cookieMetadata);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_merchant_reference' => $merchantReference ?? null,
                    ]
                ]
            );
        }

        $this->logManager->removeTag('cookies');
    }

    /**
     * Return the current quote if relevant, otherwise fetch a quote previously stored by the Quote cookie agent.
     * Lazy-loadable for better performance.
     *
     * @return \Magento\Quote\Model\Quote|null
     */
    public function fetchRelevantQuote()
    {
        if (!$this->relevantQuote) {
            $this->logManager->addTag('cookies');

            $quote = $this->checkoutSession->getQuote();

            if ($this->isQuoteRelevant($quote)) {
                return $quote;
            }

            $quote = null;

            try {
                $merchantReference = $this->cookieManager->getCookie(self::COOKIE_NAME);
                $link = $this->linkRepository->getByReference($merchantReference);
                $this->logManager->setMerchantReference($merchantReference);

                /** @var \Magento\Quote\Model\Quote $quote */
                $quote = $this->quoteRepository->get($link->getQuoteId());
            } catch (\Exception $exception) {
                $this->logManager->critical(
                    $exception,
                    [
                        'extra' => [
                            'qliro_merchant_reference' => $merchantReference ?? null,
                        ]
                    ]
                );
            }

            $this->logManager->removeTag('cookies');
            $this->relevantQuote = $quote;
        }

        $this->logManager->debug('Relevant quote fetched' . $this->relevantQuote->getId());

        return $this->relevantQuote;
    }

    /**
     * Return the merchant reference stored in the QOMR cookie, or null if not present.
     *
     * @return string|null
     */
    public function getMerchantReferenceFromCookie(): ?string
    {
        return $this->cookieManager->getCookie(self::COOKIE_NAME) ?: null;
    }

    /**
     * Clear the quote if it's stored
     */
    public function clear()
    {
        $this->logManager->addTag('cookies');
        $merchantReference = $this->cookieManager->getCookie(self::COOKIE_NAME);

        if ($merchantReference) {
            $this->logManager->setMerchantReference($merchantReference);
        }

        try {
            $cookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
            $cookieMetadata->setDuration(0);
            $cookieMetadata->setPath('/');
            $this->cookieManager->deleteCookie(self::COOKIE_NAME, $cookieMetadata);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_merchant_reference' => $merchantReference ?? null,
                    ]
                ]
            );
        }

        $this->logManager->removeTag('cookies');
    }

    /**
     * Check if the quote relevant for QliroOne Checkout
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return bool
     */
    private function isQuoteRelevant(Quote $quote)
    {
        if (empty($quote->getAllVisibleItems())) {
            return false;
        }

        try {
            $this->linkRepository->getByQuoteId($quote->getId());
        } catch (NoSuchEntityException $exception) {
            return false;
        }

        return true;
    }
}

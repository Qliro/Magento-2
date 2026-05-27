<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Success;

use Magento\Checkout\Model\Session as SuccessSession;

/**
 * Saves information for displaying in success page
 */
class Session
{
    /**
     * Class constructor
     *
     * @param SuccessSession $checkoutSession
     */
    public function __construct(
        private readonly SuccessSession $checkoutSession
    ) {
    }

    /**
     * @param string|null $snippet
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    public function save(?string $snippet, \Magento\Sales\Model\Order $order): void
    {
        $this->checkoutSession->setSuccessHtmlSnippet($snippet);
        $this->checkoutSession->setSuccessIncrementId($order->getIncrementId());
        $this->checkoutSession->setSuccessOrderId($order->getId());
        $this->checkoutSession->setSuccessHasDisplayed(false);
    }

    /**
     * Clears saves success
     *
     * @return void
     */
    public function clear(): void
    {
        $this->checkoutSession->unsSuccessHtmlSnippet();
        $this->checkoutSession->unsSuccessIncrementId();
        $this->checkoutSession->unsSuccessOrderId();
        $this->checkoutSession->unsSuccessHasDisplayed();
    }

    /**
     * @return string|null
     */
    public function getSuccessHtmlSnippet(): ?string
    {
        return $this->checkoutSession->getSuccessHtmlSnippet();
    }

    /**
     * @return string|null
     */
    public function getSuccessIncrementId(): ?string
    {
        return $this->checkoutSession->getSuccessIncrementId();
    }

    /**
     * @return int|string|null
     */
    public function getSuccessOrderId(): int|string|null
    {
        return $this->checkoutSession->getSuccessOrderId();
    }

    /**
     * @return bool
     */
    public function hasSuccessDisplayed(): bool
    {
        return (bool)$this->checkoutSession->getSuccessHasDisplayed();
    }

    /**
     * Mark success as being displayed, thus not triggering GTM etc if success page is reloaded
     *
     * @return void
     */
    public function setSuccessDisplayed(): void
    {
        $this->checkoutSession->setSuccessHasDisplayed(true);
    }
}

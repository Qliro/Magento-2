<?php

/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Plugin\PreventChangeInCartWhenLocked;

use \Magento\Checkout\Controller\Cart\CouponPost as Subject;
use \Magento\Framework\Exception\LocalizedException;
use \Magento\Framework\Exception\NoSuchEntityException;
use \Qliro\QliroOne\Api\LinkRepositoryInterface;
use \Magento\Checkout\Model\Session as CheckoutSession;
use \Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use \Magento\Framework\Controller\Result\RedirectFactory;
use \Magento\Framework\App\Response\RedirectInterface;

class Coupon
{
    /**
     * @param LinkRepositoryInterface $linkRepository
     * @param CheckoutSession $checkoutSession
     * @param MessageManagerInterface $messageManager
     * @param RedirectFactory $redirectFactory
     * @param RedirectInterface $redirect
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly MessageManagerInterface $messageManager,
        private readonly RedirectFactory $redirectFactory,
        private readonly RedirectInterface $redirect
    )
    {
    }

    /**
     * Disable quote updates for locked links
     *
     * @param Subject $subject The subject instance being intercepted.
     * @param callable $proceed The callable function to proceed with the original execution.
     */
    public function aroundExecute(Subject $subject, callable $proceed)
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (NoSuchEntityException|LocalizedException $e) {
            return $proceed();
        }

        if (!$quote || !$quote->getId()) {
            return $proceed();
        }

        try {
            $link = $this->linkRepository->getByQuoteId($quote->getId());
        } catch (NoSuchEntityException $e) {
            return $proceed();
        }

        if (!$link->getIsLocked()) {
            return $proceed();
        }

        $this->messageManager->addErrorMessage(__('You have an ongoing Qliro payment. Please complete or cancel it before updating your cart'));


        $resultRedirect = $this->redirectFactory->create();
        $resultRedirect->setUrl($this->redirect->getRefererUrl());

        return $resultRedirect;
    }
}

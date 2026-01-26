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

class Coupon extends AbstractAction
{
    /**
     * @param LinkRepositoryInterface $linkRepository
     * @param CheckoutSession $checkoutSession
     * @param MessageManagerInterface $messageManager
     * @param RedirectFactory $redirectFactory
     * @param RedirectInterface $redirect
     */
    public function __construct(
        LinkRepositoryInterface $linkRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly MessageManagerInterface $messageManager,
        private readonly RedirectFactory $redirectFactory,
        private readonly RedirectInterface $redirect
    )
    {
        parent::__construct($linkRepository);
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

        try {
            if (!$this->isLocked($quote)) {
                return $proceed();
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage(__($e->getMessage()));
        }

        $resultRedirect = $this->redirectFactory->create();
        $resultRedirect->setUrl($this->redirect->getRefererUrl());

        return $resultRedirect;
    }
}

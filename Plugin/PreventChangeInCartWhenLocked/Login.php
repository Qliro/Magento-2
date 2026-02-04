<?php

/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Plugin\PreventChangeInCartWhenLocked;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\AccountManagement as Subject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\LinkRepositoryInterface;

class Login extends AbstractAction
{
    /**
     * @param LinkRepositoryInterface $linkRepository
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        LinkRepositoryInterface $linkRepository,
        private readonly CheckoutSession $checkoutSession,
    )
    {
        parent::__construct($linkRepository);
    }

    /**
     * Pre-processing logic executed before user authentication related to the restricting for locked quotes
     *
     * @param Subject $subject The subject instance performing the authentication.
     * @param string $username The username provided for authentication.
     * @param string $password The password provided for authentication.
     * @return array Returns an array containing the username and password, possibly modified during processing.
     * @throws LocalizedException
     */
    public function beforeAuthenticate(Subject $subject, $username, $password): array
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (NoSuchEntityException|LocalizedException $e) {
            return [$username, $password];
        }

        $this->isLocked($quote);
        return [$username, $password];
    }
}

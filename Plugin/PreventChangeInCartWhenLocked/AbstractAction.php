<?php


/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Plugin\PreventChangeInCartWhenLocked;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;

abstract class AbstractAction
{
    /**
     * @param LinkRepositoryInterface $linkRepository
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
    )
    {
    }


    /**
     * Checks if the cart is locked due to an ongoing Qliro payment.
     *
     * @param CartInterface $quote The cart being checked for a locked state.
     * @return bool Returns true if the cart is locked; otherwise, false.
     * @throws LocalizedException Thrown if the cart is locked due to an ongoing Qliro payment.
     */
    protected function isLocked(CartInterface $quote): bool
    {
        if (!$quote->getId()) {
            return false;
        }

        try {
            $link = $this->linkRepository->getByQuoteId($quote->getId());
        } catch (NoSuchEntityException $e) {
            return false;
        }

        if (!$link->getIsLocked()) {
          return false;
        }

        throw new LocalizedException(
            __('You have an ongoing Qliro payment. Please complete or cancel it before updating your cart')
        );
    }
}

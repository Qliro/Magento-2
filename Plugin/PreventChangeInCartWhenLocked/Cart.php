<?php

/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Plugin\PreventChangeInCartWhenLocked;

use Magento\Checkout\Model\Cart as Subject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;

class Cart
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
     *  Prevent card mutation by adding items for locked quote
     *
     * @param Subject $subject The subject instance on which the method is invoked
     * @param mixed $productInfo Information about the product being added
     * @param mixed|null $requestInfo Additional request information, optional
     * @return array Returns an array containing the product information and request information
     * @throws LocalizedException
     */
    public function beforeAddProduct(Subject $subject, $productInfo, $requestInfo = null): array
    {
        $this->isLocked($subject->getQuote());

        return [$productInfo, $requestInfo];
    }

    /**
     * Prevent card mutation by updating items for locked quote
     *
     * @param Subject $subject The subject being intercepted, typically handling the update items process.
     * @param array $data The array of item data to be updated.
     * @return array The modified or verified item data array.
     * @throws LocalizedException
     */
    public function beforeUpdateItems(Subject $subject, array $data): array
    {
        $this->isLocked($subject->getQuote());

        return [$data];
    }

    /**
     * @param Subject $subject The subject instance containing the quote information.
     * @param mixed $itemId The ID of the item to be updated.
     * @param mixed|null $requestInfo Additional request information for the update (optional).
     * @param mixed|null $updatingParams Parameters for updating the item (optional).
     *
     * @return array An array containing the item ID, request information, and updating parameters.
     */
    public function beforeUpdateItem(Subject $subject, $itemId, $requestInfo = null, $updatingParams = null)
    {
        $this->isLocked($subject->getQuote());

        return [$itemId, $requestInfo, $updatingParams];
    }

    /**
     * Prevent card mutation by removing items for locked quote
     *
     * @param Subject $subject The subject instance containing the quote to be checked.
     * @param mixed $itemId The ID of the item to be removed.
     * @return array Returns the processed list of item IDs.
     * @throws LocalizedException
     */
    public function beforeRemoveItem(Subject $subject, $itemId): array
    {
        $this->isLocked($subject->getQuote());

        return [$itemId];
    }

    /**
     * Checks if the cart is locked and throws an exception if it is.
     *
     * @param CartInterface $quote The cart instance to check for the locked status.
     * @return void
     * @throws LocalizedException If the cart is locked and contains a Qliro payment.
     */
    private function isLocked(CartInterface $quote): void
    {
        if (!$quote->getId()) {
            return;
        }

        try {
            $link = $this->linkRepository->getByQuoteId($quote->getId());
        } catch (NoSuchEntityException $e) {
            return;
        }

        if ($link->getIsLocked()) {
            throw new LocalizedException(
                __('You have an ongoing Qliro payment. Please complete or cancel it before updating your cart')
            );
        }

    }
}

<?php declare(strict_types=1);
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\HashResolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\HashResolverInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;

/**
 * Returns the quote's reserved Magento order increment id as the reference.
 *
 * STRICT INVARIANT: the returned value is always the quote's reserved
 * increment id — never a random hash, never a suffix. If the current
 * reserved id is already used by an existing qliroone_link row (e.g. a
 * previous abandoned attempt for this quote), the Magento order sequence
 * is bumped to obtain a fresh increment id, and the new reservation is
 * persisted on the quote.
 */
class IncrementIdHashResolver implements HashResolverInterface
{

     const MAX_BUMP_ATTEMPTS = 10;

    /**
     * @param CartRepositoryInterface $quoteRepository
     * @param LinkRepositoryInterface $linkRepository
     */
    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly LinkRepositoryInterface $linkRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolveHash(CartInterface $quote): string
    {
        if (!$quote instanceof Quote) {
            throw new LocalizedException(
                __('A reservable quote is required to use the increment ID as the Qliro merchant reference.')
            );
        }

        if (empty($quote->getReservedOrderId())) {
            $quote->reserveOrderId();
        }

        $bumped = false;
        for ($attempt = 0; $attempt < self::MAX_BUMP_ATTEMPTS; $attempt++) {
            $reference = (string) $quote->getReservedOrderId();

            if ($reference === '') {
                throw new LocalizedException(
                    __('Could not obtain a reserved increment ID for the quote.')
                );
            }

            if (!$this->isReferenceTaken($reference)) {
                if ($bumped) {
                    // Persist the new reservation so the eventual placed order uses this id (and not the previously stored one).
                    $this->quoteRepository->save($quote);
                }
                return $reference;
            }

            // Reference already used by a previous link row. Bump the Magento order sequence to obtain a fresh one.
            $quote->setReservedOrderId(null);
            $quote->reserveOrderId();
            $bumped = true;
        }

        throw new LocalizedException(
            __(
                'Failed to obtain a unique Magento increment ID after %1 attempts.',
                self::MAX_BUMP_ATTEMPTS
            )
        );
    }

    /**
     * Determines if a given reference is already taken.
     *
     * @param string $reference The reference to check.
     * @return bool True if the reference is taken, false otherwise.
     */
    private function isReferenceTaken(string $reference): bool
    {
        try {
            $this->linkRepository->getByReference($reference, false);
            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }
}

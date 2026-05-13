<?php declare(strict_types=1);
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\HashResolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartInterface;
use Qliro\QliroOne\Api\HashResolverInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;

/**
 * Class responsible for generating a random hash for a given cart instance.
 * Implements the HashResolverInterface.
 *
 * Returns the shortest unique prefix of a random 25-character hash, starting
 * at REFERENCE_MIN_LENGTH. If a prefix is already used in qliroone_link, the
 * search extends one character at a time up to HASH_MAX_LENGTH; if exhausted,
 * a new random hash is generated and the search restarts.
 */
class RandomHashResolver implements HashResolverInterface
{
    /**
     * Defines a character set containing digits, lowercase, and uppercase letters.
     */
    private const CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Defines the maximum length for a hash value.
     */
    private const HASH_MAX_LENGTH = 25;

    /**
     * Defines the starting length for a hash prefix.
     */
    private const REFERENCE_MIN_LENGTH = 6;

    /**
     * @param LinkRepositoryInterface $linkRepository
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolveHash(CartInterface $quote): string
    {
        $hash = $this->generateRandomHash();
        $length = self::REFERENCE_MIN_LENGTH;

        while (true) {
            $candidate = substr($hash, 0, $length);

            try {
                $this->linkRepository->getByReference($candidate);
                // Collision: try a longer prefix; if exhausted, regenerate.
                if (++$length > self::HASH_MAX_LENGTH) {
                    $hash = $this->generateRandomHash();
                    $length = self::REFERENCE_MIN_LENGTH;
                }
            } catch (NoSuchEntityException $e) {
                return $candidate;
            }
        }
    }

    /**
     * Generates a random hash of HASH_MAX_LENGTH characters from CHARSET.
     *
     * @return string
     */
    private function generateRandomHash(): string
    {
        $charset = self::CHARSET;
        $maxIndex = strlen($charset) - 1;
        $hash = '';
        for ($i = 0; $i < self::HASH_MAX_LENGTH; $i++) {
            $hash .= $charset[random_int(0, $maxIndex)];
        }

        return $hash;
    }
}

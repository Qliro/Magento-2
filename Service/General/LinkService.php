<?php declare(strict_types=1);

namespace Qliro\QliroOne\Service\General;

use DomainException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\HashResolverInterface;

/**
 * Service class for generating necessary data for Link entities.
 *
 * The actual hash strategy (random vs. Magento increment ID) is selected
 * by the injected HashResolverInterface implementation. Each resolver is
 * responsible for producing a final, unique, validation-ready reference.
 */
class LinkService
{
    /**
     * @param HashResolverInterface $hashResolver
     */
    public function __construct(
        private readonly HashResolverInterface $hashResolver
    ) {
    }

    /**
     * Generates an order reference by resolving a hash value from the provided quote.
     *
     * @param Quote $quote The quote object used to generate the order reference.
     * @return string The generated order reference.
     *
     * @throws LocalizedException If the resolver cannot produce a reference (e.g. failed to obtain a unique increment ID).
     * @throws DomainException If the resulting reference does not match Qliro's accepted format.
     */
    public function generateOrderReference(Quote $quote): string
    {
        $hash = $this->hashResolver->resolveHash($quote);
        $this->validateHash($hash);

        return $hash;
    }

    /**
     * Validates the given hash against a predefined pattern.
     *
     * @param string $hash The hash string to be validated.
     * @return void
     *
     * @throws DomainException If the hash does not match the required pattern.
     */
    private function validateHash(string $hash): void
    {
        if (!preg_match(HashResolverInterface::VALIDATE_MERCHANT_REFERENCE, $hash)) {
            throw new DomainException(
                sprintf('Merchant reference \'%s\' will not be accepted by Qliro', $hash)
            );
        }
    }
}

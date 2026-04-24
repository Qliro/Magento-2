<?php declare(strict_types=1);

namespace Qliro\QliroOne\Service\General;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\HashResolverInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;

/**
 * Service class for generating necessary data for Link entities
 */
class LinkService
{
    const REFERENCE_MIN_LENGTH = 6;

    public function __construct(
        private HashResolverInterface $hashResolver,
        private LinkRepositoryInterface $linkRepository,
        private Config $qliroConfig
    ) {
    }

    /**
     * Generate a QliroOne unique order reference
     *
     * When the admin setting "Use Magento Increment ID as a reference" is on,
     * the resolver returns the quote's reserved Magento increment ID and we use
     * it verbatim — increment IDs are already globally unique, so neither the
     * substring truncation nor the uniqueness loop below are appropriate.
     *
     * @param Quote $quote
     * @return string
     */
    public function generateOrderReference(Quote $quote): string
    {
        $hash = $this->hashResolver->resolveHash($quote);
        $this->validateHash($hash);

        if ($this->qliroConfig->isUseIncrementIdAsReference((int) $quote->getStoreId())) {
            return $hash;
        }

        $hashLength = self::REFERENCE_MIN_LENGTH;

        do {
            $isUnique = false;
            $shortenedHash = substr($hash, 0, $hashLength);

            try {
                $this->linkRepository->getByReference($shortenedHash);

                if ((++$hashLength) > HashResolverInterface::HASH_MAX_LENGTH) {
                    $hash = $this->hashResolver->resolveHash($quote);
                    $this->validateHash($hash);
                    $hashLength = self::REFERENCE_MIN_LENGTH;
                }
            } catch (NoSuchEntityException $exception) {
                $isUnique = true;
            }
        } while (!$isUnique);

        return $shortenedHash;
    }

    /**
     * Validate hash against QliroOne order merchant reference requirements
     *
     * @param string $hash
     */
    private function validateHash($hash)
    {
        if (!preg_match(HashResolverInterface::VALIDATE_MERCHANT_REFERENCE, $hash)) {
            throw new \DomainException(sprintf('Merchant reference \'%s\' will not be accepted by Qliro', $hash));
        }
    }
}

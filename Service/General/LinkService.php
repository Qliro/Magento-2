<?php declare(strict_types=1);

namespace Qliro\QliroOne\Service\General;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\HashResolverInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;

/**
 * Service class for generating necessary data for Link entities
 */
class LinkService
{
    const REFERENCE_MIN_LENGTH = 6;

    /**
     * Class constructor
     *
     * @param \Qliro\QliroOne\Api\HashResolverInterface $hashResolver
     * @param \Qliro\QliroOne\Api\LinkRepositoryInterface $linkRepository
     */
    public function __construct(
        private readonly HashResolverInterface $hashResolver,
        private readonly LinkRepositoryInterface $linkRepository
    ) {
    }

    /**
     * Deactivate a link by clearing its Qliro order ID and marking it inactive.
     *
     * Moved from Service\Checkout\LinkManager (dead-weight elimination).
     *
     * @param LinkInterface $link
     * @throws AlreadyExistsException
     */
    public function deactivate(LinkInterface $link): void
    {
        $link->setIsActive(false);
        $link->setQliroOrderId(null);
        $this->linkRepository->save($link);
    }

    /**
     * Generate a QliroOne unique order reference
     *
     * @param Quote $quote
     * @return string
     */
    public function generateOrderReference(Quote $quote): string
    {
        $hash = $this->hashResolver->resolveHash($quote);
        $this->validateHash($hash);
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

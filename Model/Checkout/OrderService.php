<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Checkout\OrderServiceInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\CheckoutStatus as CheckoutStatusManagement;
use Qliro\QliroOne\Model\Management\QliroOrder as QliroOrderManagement;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Management\ShippingMethod as ShippingMethodManagement;
use Qliro\QliroOne\Model\Quote\Agent;

/**
 * Checkout lifecycle service
 *
 */
class OrderService implements OrderServiceInterface
{
    /**
     * Class constructor
     *
     * @param CheckoutSession                     $checkoutSession
     * @param QliroOrderManagement                $qliroOrderManagement
     * @param CheckoutStatusManagement            $checkoutStatusManagement
     * @param ShippingMethodManagement            $shippingMethodManagement
     * @param QuoteManagement                     $quoteManagement
     * @param LinkRepositoryInterface             $linkRepository
     * @param Agent                               $quoteAgent
     * @param LogManager                          $logManager
     */
    public function __construct(
        private readonly CheckoutSession          $checkoutSession,
        private readonly QliroOrderManagement     $qliroOrderManagement,
        private readonly CheckoutStatusManagement $checkoutStatusManagement,
        private readonly ShippingMethodManagement $shippingMethodManagement,
        private readonly QuoteManagement          $quoteManagement,
        private readonly LinkRepositoryInterface  $linkRepository,
        private readonly Agent                    $quoteAgent,
        private readonly LogManager               $logManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getQliroOrder(bool $allowRecreate = true): array
    {
        $quote = $this->getQuote();

        try {
            try {
                $this->linkRepository->unlock((int)$quote->getId());
            } catch (NoSuchEntityException $e) {
                // No link yet — nothing to unlock
            }

            $qliroOrder = $this->qliroOrderManagement->get($quote, $allowRecreate);
            $this->quoteAgent->store($quote);

            return $qliroOrder;

        } catch (\Exception $exception) {
            $this->logManager->critical(
                sprintf('QliroOne Checkout has failed to load. %s', $exception->getMessage()),
                ['exception' => $exception, 'extra' => $exception->getTrace()]
            );

            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function checkoutStatus(array $checkoutStatus): array
    {
        return $this->checkoutStatusManagement->update($checkoutStatus);
    }

    /**
     * @inheritDoc
     */
    public function getShippingMethods(array $updateContainer): array
    {
        return $this->shippingMethodManagement->get($updateContainer);
    }

    /**
     * @inheritDoc
     */
    public function validateQliroOrder(array $validateContainer): array
    {
        return $this->qliroOrderManagement->validate($validateContainer);
    }

    /**
     * @inheritDoc
     */
    public function updateCustomer(array $customerData): void
    {
        $this->quoteManagement->updateCustomer($this->getQuote(), $customerData);
    }

    /**
     * @inheritDoc
     */
    public function updateShippingMethod(string $code, ?string $secondaryOption = null, ?float $price = null): bool
    {
        return $this->shippingMethodManagement->update($this->getQuote(), $code, $secondaryOption, $price);
    }

    /**
     * @inheritDoc
     */
    public function updateShippingPrice(?float $price): bool
    {
        return $this->quoteManagement->updateShippingPrice($this->getQuote(), $price);
    }

    /**
     * @inheritDoc
     */
    public function updateFee(float $fee): bool
    {
        return $this->quoteManagement->updateFee($this->getQuote(), $fee);
    }

    /**
     * Return the current session quote
     */
    private function getQuote(): Quote
    {
        return $this->checkoutSession->getQuote();
    }

}

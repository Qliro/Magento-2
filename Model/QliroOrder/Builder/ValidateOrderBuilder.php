<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\Exception;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\SubmitQuoteValidator;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Quote\Model\CustomerManagement;
use Qliro\QliroOne\Model\QliroOrder\Validator\QuoteItemComparator;

/**
 * Validate Order Response Builder
 */
class ValidateOrderBuilder
{
    private ?array $validationRequest = null;
    private ?CartInterface $quote = null;

    /**
     * Class constructor
     *
     * @param QuoteItemComparator $quoteItemComparator
     * @param OrderItemsBuilder $orderItemsBuilder
     * @param LogManager $logManager
     * @param SubmitQuoteValidator $submitQuoteValidator
     * @param CustomerManagement $customerManagement
     */
    public function __construct(
        private readonly QuoteItemComparator $quoteItemComparator,
        private readonly OrderItemsBuilder $orderItemsBuilder,
        private readonly LogManager $logManager,
        private readonly SubmitQuoteValidator $submitQuoteValidator,
        private readonly CustomerManagement $customerManagement
    ) {
    }

    /**
     * @param CartInterface $quote
     * @return $this
     */
    public function setQuote(CartInterface $quote): static
    {
        $this->quote = $quote;
        return $this;
    }

    /**
     * @param array $validationRequest
     * @return static
     */
    public function setValidationRequest(array $validationRequest): static
    {
        $this->validationRequest = $validationRequest;
        return $this;
    }

    /**
     * @return array  Response payload with optional DeclineReason key
     */
    public function create(): array
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        if (empty($this->validationRequest)) {
            throw new \LogicException('QliroOne validation request is not set.');
        }

        if (!$this->quoteItemComparator->checkInStock($this->quote)) {
            $this->logManager->debug('Not all products are in stock: ' . $this->quote->getId());
            $this->reset();
            return ['DeclineReason' => 'OutOfStock'];
        }

        if (!$this->isQliroShippingDataValid()) {
            $this->logValidateError('create', 'No shipping method selected in qliro');
            $this->reset();
            return ['DeclineReason' => 'NoShippingMethod'];
        }

        if (!$this->quote->isVirtual() && !$this->quote->getShippingAddress()->getShippingMethod()) {
            $method = $this->quote->getShippingAddress()->getShippingMethod();
            $this->logValidateError('create', 'not a virtual order, invalid shipping method selected', ['method' => $method]);
            $this->reset();
            return ['DeclineReason' => 'NoShippingMethod'];
        }

        try {
            $this->logManager->debug('Starting to validate address for quote id: ' . $this->quote->getId());
            $this->customerManagement->validateAddresses($this->quote);
        } catch (Exception $e) {
            $this->logManager->debug('Validation address failed for quote id: ' . $this->quote->getId());
            $this->logValidateError('create', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->reset();
            return ['DeclineReason' => 'PostalCode'];
        }

        $orderItemsFromQuote = $this->orderItemsBuilder->setQuote($this->quote)->create();
        $qliroOrderItems     = $this->validationRequest['OrderItems'] ?? [];

        $this->logManager->debug('Starting to compare quote and Qliro order items: ' . $this->quote->getId());
        if (!$this->quoteItemComparator->compare($orderItemsFromQuote, $qliroOrderItems)) {
            $this->logManager->debug('Not all order lines match: ' . $this->quote->getId());
            $this->reset();
            return ['DeclineReason' => 'Other'];
        }

        try {
            $this->logManager->debug('Starting to validate quote: ' . $this->quote->getId());
            $this->submitQuoteValidator->validateQuote($this->quote);
            $this->logManager->debug('Finished to validate quote: ' . $this->quote->getId());
        } catch (Exception|LocalizedException $e) {
            $this->logManager->debug('Validation failed for quote: ' . $this->quote->getId());
            $this->logValidateError('create', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->reset();
            return ['DeclineReason' => 'Other'];
        }

        $this->reset();
        return [];
    }

    private function isQliroShippingDataValid(): bool
    {
        if ($this->quote->isVirtual()) {
            return true;
        }

        $selectedMethod = $this->validationRequest['SelectedShippingMethod'] ?? null;
        if (empty($selectedMethod)) {
            return false;
        }

        foreach ($this->validationRequest['OrderItems'] ?? [] as $item) {
            if (($item['Type'] ?? null) === QliroOrderItemInterface::TYPE_SHIPPING) {
                return true;
            }
        }

        return false;
    }

    private function reset(): void
    {
        $this->quote = null;
        $this->validationRequest = null;
    }

    private function logValidateError(string $function, string $reason, array $details = []): void
    {
        $this->logManager->debug('CALLBACK:VALIDATE', [
            'extra' => [
                'function' => $function,
                'reason'   => $reason,
                'details'  => $details,
            ],
        ]);
    }
}

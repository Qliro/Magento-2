<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Method;

use Magento\Framework\DataObject;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\Method\Adapter;
use Magento\Quote\Api\Data\CartInterface;

/**
 * QliroOne payment method class
 */
class QliroOne implements MethodInterface
{
    const PAYMENT_METHOD_CHECKOUT_CODE = 'qliroone';
    const PAYMENT_METHOD_FORM_BLOCK_TYPE = 'Qliro\QliroOne\Block\Form\QliroOne';
    const PAYMENT_METHOD_INFO_BLOCK_TYPE = 'Qliro\QliroOne\Block\Info\QliroOne';

    /**
     * Class constructor
     *
     * @param Adapter $adapter
     */
    public function __construct(
        private readonly Adapter $adapter
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return $this->adapter->getCode();
    }

    /**
     * @inheritdoc
     */
    public function getFormBlockType(): string
    {
        return $this->adapter->getFormBlockType();
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        return $this->adapter->getTitle();
    }

    /**
     * @inheritdoc
     */
    public function setStore(mixed $storeId): void
    {
        $this->adapter->setStore($storeId);
    }

    /**
     * @inheritdoc
     */
    public function getStore(): mixed
    {
        return $this->adapter->getStore();
    }

    /**
     * @inheritdoc
     */
    public function canOrder(): bool
    {
        return $this->adapter->canOrder();
    }

    /**
     * @inheritdoc
     */
    public function canAuthorize(): bool
    {
        return $this->adapter->canAuthorize();
    }

    /**
     * @inheritdoc
     */
    public function canCapture(): bool
    {
        return $this->adapter->canCapture();
    }

    /**
     * @inheritdoc
     */
    public function canCapturePartial(): bool
    {
        return $this->adapter->canCapturePartial();
    }

    /**
     * @inheritdoc
     */
    public function canCaptureOnce(): bool
    {
        return $this->adapter->canCaptureOnce();
    }

    /**
     * @inheritdoc
     */
    public function canRefund(): bool
    {
        return $this->adapter->canRefund();
    }

    /**
     * @inheritdoc
     */
    public function canRefundPartialPerInvoice(): bool
    {
        return $this->adapter->canRefundPartialPerInvoice();
    }

    /**
     * @inheritdoc
     */
    public function canVoid(): bool
    {
        return $this->adapter->canVoid();
    }

    /**
     * @inheritdoc
     */
    public function canUseInternal(): bool
    {
        return $this->adapter->canUseInternal();
    }

    /**
     * @inheritdoc
     */
    public function canUseCheckout(): bool
    {
        return $this->adapter->canUseCheckout();
    }

    /**
     * @inheritdoc
     */
    public function canEdit(): bool
    {
        return $this->adapter->canEdit();
    }

    /**
     * @inheritdoc
     */
    public function canFetchTransactionInfo(): bool
    {
        return $this->adapter->canFetchTransactionInfo();
    }

    /**
     * @inheritdoc
     */
    public function fetchTransactionInfo(InfoInterface $payment, mixed $transactionId): mixed
    {
        return $this->adapter->fetchTransactionInfo($payment, $transactionId);
    }

    /**
     * @inheritdoc
     */
    public function isGateway(): bool
    {
        return $this->adapter->isGateway();
    }

    /**
     * @inheritdoc
     */
    public function isOffline(): bool
    {
        return $this->adapter->isOffline();
    }

    /**
     * @inheritdoc
     */
    public function isInitializeNeeded(): bool
    {
        return $this->adapter->isInitializeNeeded();
    }

    /**
     * @inheritdoc
     */
    public function canUseForCountry(mixed $country): bool
    {
        return $this->adapter->canUseForCountry($country);
    }

    /**
     * @inheritdoc
     */
    public function canUseForCurrency(mixed $currencyCode): bool
    {
        return $this->adapter->canUseForCurrency($currencyCode);
    }

    /**
     * @inheritdoc
     */
    public function getInfoBlockType(): string
    {
        return $this->adapter->getInfoBlockType();
    }

    /**
     * @inheritdoc
     */
    public function getInfoInstance(): InfoInterface
    {
        return $this->adapter->getInfoInstance();
    }

    /**
     * @inheritdoc
     */
    public function setInfoInstance(InfoInterface $info): void
    {
        $this->adapter->setInfoInstance($info);
    }

    /**
     * @inheritdoc
     */
    public function validate(): static
    {
        $this->adapter->validate();
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function order(InfoInterface $payment, mixed $amount): static
    {
        throw new \Exception("order - feature not implemented\n");
    }

    /**
     * @inheritdoc
     */
    public function authorize(InfoInterface $payment, mixed $amount): static
    {
        throw new \Exception("authorize - feature not implemented\n");
    }

    /**
     * @inheritdoc
     */
    public function capture(InfoInterface $payment, mixed $amount): static
    {
        $this->adapter->capture($payment, $amount);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function refund(InfoInterface $payment, mixed $amount): static
    {
        $this->adapter->refund($payment, $amount);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function cancel(InfoInterface $payment): static
    {
        $this->adapter->cancel($payment);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function void(InfoInterface $payment): static
    {
        $this->adapter->void($payment);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function canReviewPayment(): bool
    {
        return $this->adapter->canReviewPayment();
    }

    /**
     * @inheritdoc
     */
    public function acceptPayment(InfoInterface $payment): bool
    {
        return $this->adapter->acceptPayment($payment);
    }

    /**
     * @inheritdoc
     */
    public function denyPayment(InfoInterface $payment): bool
    {
        return $this->adapter->denyPayment($payment);
    }

    /**
     * @inheritdoc
     */
    public function getConfigData(mixed $field, mixed $storeId = null): mixed
    {
        return $this->adapter->getConfigData($field, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function assignData(DataObject $data): static
    {
        $this->adapter->assignData($data);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function isAvailable(?CartInterface $quote = null): bool
    {
        return $this->adapter->isAvailable($quote);
    }

    /**
     * @inheritdoc
     */
    public function isActive(mixed $storeId = null): bool
    {
        return $this->adapter->isActive($storeId);
    }

    /**
     * @inheritdoc
     */
    public function initialize(mixed $paymentAction, mixed $stateObject): void
    {
        $this->adapter->initialize($paymentAction, $stateObject);
    }

    /**
     * @inheritdoc
     */
    public function getConfigPaymentAction(): ?string
    {
        return $this->adapter->getConfigPaymentAction();
    }
}

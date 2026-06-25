<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder\Handler;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;

final class AppliedRulesHandler implements OrderItemHandlerInterface
{
    private const DISCOUNT_REFERENCE_PREFIX = 'DSC';
    private const DEFAULT_DISCOUNT_REFERENCE = 'DSC_QUOTE_DISCOUNT';
    private const EPSILON = 0.0001;

    /**
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param QliroHelper $qliroHelper
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly QliroHelper                    $qliroHelper,
        private readonly ManagerInterface               $eventManager
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function handle($orderItems, $quote): array
    {
        if (!$quote instanceof Quote) {
            return $orderItems;
        }

        $address = $quote->isVirtual()
            ? $quote->getBillingAddress()
            : $quote->getShippingAddress();

        $discountInclVat = abs((float)$address->getDiscountAmount());

        if ($discountInclVat <= self::EPSILON) {
            return $orderItems;
        }

        $discountExclVat = $this->getDiscountExclVat($quote, $discountInclVat);
        $vatRate = $this->calculateVatRate($discountInclVat, $discountExclVat);
        $merchantReference = $this->getMerchantReference($quote);

        /** @var QliroOrderItemInterface $qliroOrderItem */
        $qliroOrderItem = $this->qliroOrderItemFactory->create();

        $qliroOrderItem->setMerchantReference($merchantReference);
        $qliroOrderItem->setDescription($merchantReference);
        $qliroOrderItem->setType(QliroOrderItemInterface::TYPE_DISCOUNT);
        $qliroOrderItem->setQuantity(1);
        $qliroOrderItem->setPricePerItemIncVat(
            -abs((float)$this->qliroHelper->formatPrice($discountInclVat))
        );
        $qliroOrderItem->setPricePerItemExVat(
            -abs((float)$this->qliroHelper->formatPrice($discountExclVat))
        );
        $qliroOrderItem->setVatRate($vatRate);
        $qliroOrderItem->setMetadata([
            'qliro' => 'checkout'
        ]);

        $this->eventManager->dispatch(
            'qliroone_order_item_build_after',
            [
                'quote' => $quote,
                'container' => $qliroOrderItem,
            ]
        );

        if ($qliroOrderItem->getMerchantReference()) {
            $orderItems[] = $qliroOrderItem;
        }

        return $orderItems;
    }

    /**
     * Generates a merchant reference based on the applied rule IDs of the given quote.
     *
     * @param Quote $quote The quote containing the applied rule IDs.
     * @return string The generated merchant reference.
     */
    private function getMerchantReference(Quote $quote): string
    {
        $ruleIds = trim((string)$quote->getAppliedRuleIds());

        if ($ruleIds === '') {
            return self::DEFAULT_DISCOUNT_REFERENCE;
        }

        return sprintf(
            '%s_%s',
            self::DISCOUNT_REFERENCE_PREFIX,
            str_replace(',', '_', $ruleIds)
        );
    }

    /**
     * Calculates the discount amount excluding VAT based on the given discount including VAT and the tax compensation amount.
     *
     * @param Quote $quote The quote containing address information for determining tax compensation.
     * @param float $discountInclVat The discount amount including VAT.
     * @return float The discount amount excluding VAT.
     */
    private function getDiscountExclVat(Quote $quote, float $discountInclVat): float
    {
        $address = $quote->isVirtual()
            ? $quote->getBillingAddress()
            : $quote->getShippingAddress();

        $discountTax = abs((float)$address->getDiscountTaxCompensationAmount());

        if ($discountTax <= self::EPSILON || $discountTax >= $discountInclVat) {
            return $discountInclVat;
        }

        return $discountInclVat - $discountTax;
    }

    /**
     * Calculates the VAT rate based on the provided discount amounts inclusive and exclusive of VAT.
     *
     * @param float $discountInclVat The discount amount inclusive of VAT.
     * @param float $discountExclVat The discount amount exclusive of VAT.
     * @return float The calculated VAT rate as a percentage.
     */
    private function calculateVatRate(float $discountInclVat, float $discountExclVat): float
    {
        if ($discountExclVat <= self::EPSILON) {
            return 0.0;
        }

        return (($discountInclVat - $discountExclVat) / $discountExclVat) * 100;
    }
}

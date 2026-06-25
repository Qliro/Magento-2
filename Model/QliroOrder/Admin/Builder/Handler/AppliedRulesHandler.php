<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;

final class AppliedRulesHandler implements OrderItemHandlerInterface
{
    private const DISCOUNT_REFERENCE_PREFIX = 'DSC';
    private const DEFAULT_DISCOUNT_REFERENCE = 'DSC_ORDER_DISCOUNT';
    private const EPSILON = 0.0001;

    /**
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param QliroHelper $qliroHelper
     */
    public function __construct(
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly QliroHelper                    $qliroHelper
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function handle($orderItems, $order): array
    {
        if (!$order instanceof Order || !$order->getFirstCaptureFlag()) {
            return $orderItems;
        }

        $discountInclVat = abs((float)$order->getDiscountAmount());

        if ($discountInclVat <= self::EPSILON) {
            return $orderItems;
        }

        $discountExclVat = $this->getDiscountExclVat($order, $discountInclVat);
        $vatRate = $this->calculateVatRate($discountInclVat, $discountExclVat);
        $merchantReference = $this->getMerchantReference($order);

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

        $orderItems[] = $qliroOrderItem;

        return $orderItems;
    }

    /**
     * Generates a merchant reference based on the discount rules applied to the given order.
     *
     * @param Order $order The order object containing the applied discount rule IDs.
     * @return string The generated merchant reference string for the order.
     */
    private function getMerchantReference(Order $order): string
    {
        $ruleIds = trim((string)$order->getAppliedRuleIds());

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
     * Calculates the discount amount excluding VAT based on the provided order and the discount including VAT.
     *
     * @param Order $order The order object containing details about the applied discounts.
     * @param float $discountInclVat The discount amount including VAT.
     * @return float The discount amount excluding VAT.
     */
    private function getDiscountExclVat(Order $order, float $discountInclVat): float
    {
        $discountTax = abs((float)$order->getDiscountTaxCompensationAmount());

        if ($discountTax <= self::EPSILON || $discountTax >= $discountInclVat) {
            return $discountInclVat;
        }

        return $discountInclVat - $discountTax;
    }

    /**
     * Calculates the VAT rate based on the given discount amounts inclusive and exclusive of VAT.
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

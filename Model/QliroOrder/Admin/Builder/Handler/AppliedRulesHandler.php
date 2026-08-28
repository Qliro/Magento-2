<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;

final class AppliedRulesHandler implements OrderItemHandlerInterface
{
    private const DISCOUNT_REFERENCE_PREFIX = 'DSC';
    private const DEFAULT_DISCOUNT_REFERENCE = 'DSC_ORDER_DISCOUNT';

    /**
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param QliroHelper $qliroHelper
     * @param DiscountAmountResolver $discountAmountResolver
     */
    public function __construct(
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly QliroHelper                    $qliroHelper,
        private readonly DiscountAmountResolver         $discountAmountResolver
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

        if (abs((float)$order->getDiscountAmount()) <= DiscountAmountResolver::EPSILON) {
            return $orderItems;
        }

        [$discountInclVat, $discountExclVat] = $this->discountAmountResolver->resolve(
            $order,
            $this->getLineVatRates($order),
            (int)$order->getStoreId(),
            $this->reservationCarriesDiscountVat($order)
        );
        $vatRate = $this->discountAmountResolver->getVatRate($discountInclVat, $discountExclVat);
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
     * Whether the reservation this capture has to match carries the VAT of the discount
     *
     * Stamped on the payment when the module places the order. An order placed before 1.7.18 has no
     * stamp and was reserved with the discount VAT free, and Qliro refuses a capture whose lines
     * disagree with the reservation, so that line has to go out the way it went out then.
     *
     * @param Order $order
     * @return bool
     */
    private function reservationCarriesDiscountVat(Order $order): bool
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return false;
        }

        return (bool)$payment->getAdditionalInformation(
            Config::QLIROONE_ADDITIONAL_INFO_DISCOUNT_CARRIES_VAT
        );
    }

    /**
     * The VAT rates of the lines the discount is spread over, so the resolver can tell a discount
     * VAT the order could have produced from one it could not. An upper bound is all it needs, so
     * the children of a configurable or a bundle are left in.
     *
     * @param Order $order
     * @return float[]
     */
    private function getLineVatRates(Order $order): array
    {
        return array_map(
            static fn($item): float => (float)$item->getTaxPercent(),
            $order->getAllItems()
        );
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
}

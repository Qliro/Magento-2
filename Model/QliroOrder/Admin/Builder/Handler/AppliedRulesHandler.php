<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
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
            (int)$order->getStoreId()
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

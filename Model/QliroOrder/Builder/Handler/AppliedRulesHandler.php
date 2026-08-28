<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder\Handler;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;

final class AppliedRulesHandler implements OrderItemHandlerInterface
{
    private const DISCOUNT_REFERENCE_PREFIX = 'DSC';
    private const DEFAULT_DISCOUNT_REFERENCE = 'DSC_QUOTE_DISCOUNT';

    /**
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param QliroHelper $qliroHelper
     * @param ManagerInterface $eventManager
     * @param DiscountAmountResolver $discountAmountResolver
     */
    public function __construct(
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly QliroHelper                    $qliroHelper,
        private readonly ManagerInterface               $eventManager,
        private readonly DiscountAmountResolver         $discountAmountResolver
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

        if (abs((float)$address->getDiscountAmount()) <= DiscountAmountResolver::EPSILON) {
            return $orderItems;
        }

        [$discountInclVat, $discountExclVat] = $this->discountAmountResolver->resolve(
            $address,
            (int)$quote->getStoreId()
        );
        $vatRate = $this->discountAmountResolver->getVatRate($discountInclVat, $discountExclVat);
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
}

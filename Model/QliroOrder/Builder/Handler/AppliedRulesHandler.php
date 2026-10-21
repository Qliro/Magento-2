<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder\Handler;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;
use Qliro\QliroOne\Model\QliroOrder\LineReference;
use Qliro\QliroOne\Model\Quote\ItemsLimitValidator;

/**
 * The cart discount as lines of its own, one per product line, see {@see DiscountLines}
 */
final class AppliedRulesHandler implements OrderItemHandlerInterface
{
    /**
     * @param ManagerInterface $eventManager
     * @param DiscountAmountResolver $discountAmountResolver
     * @param DiscountLines $discountLines
     * @param LineReference $lineReference
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly ManagerInterface       $eventManager,
        private readonly DiscountAmountResolver $discountAmountResolver,
        private readonly DiscountLines          $discountLines,
        private readonly LineReference          $lineReference,
        private readonly LogManager             $logManager
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

        $storeId = (int)$quote->getStoreId();
        $discountLines = [];

        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
                continue;
            }

            $reference = $orderItem->getMerchantReference();
            $itemId = $this->lineReference->itemIdOf($reference);
            $item = $itemId === null ? null : $quote->getItemById($itemId);
            $discountItem = $item ? $this->discountLines->discountItemOf($item) : null;

            if ($discountItem === null) {
                continue;
            }

            array_push(
                $discountLines,
                ...$this->discountLines->forProductLine($reference, (int)$orderItem->getQuantity(), $discountItem, $storeId)
            );
        }

        if (!$quote->isVirtual()) {
            $shippingLine = $this->discountLines->forShipping($address, $storeId);

            if ($shippingLine) {
                $discountLines[] = $shippingLine;
            }
        }

        $missing = $this->getMissingDiscount($address, $quote, $discountLines);

        if ($missing) {
            $discountLines[] = $missing;
        }

        // Qliro takes so many lines and no more; one is left for the rounding line added after this
        if (count($orderItems) + count($discountLines) >= ItemsLimitValidator::MAX_ITEMS) {
            $discountLines = [$this->getWholeDiscount($address, $quote)];
        }

        foreach ($discountLines as $discountLine) {
            $this->eventManager->dispatch(
                'qliroone_order_item_build_after',
                [
                    'quote' => $quote,
                    'container' => $discountLine,
                ]
            );

            if ($discountLine->getMerchantReference()) {
                $orderItems[] = $discountLine;
            }
        }

        return $orderItems;
    }

    /**
     * The whole cart discount as one order level line
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @param Quote $quote
     * @return QliroOrderItemInterface
     */
    private function getWholeDiscount($address, Quote $quote): QliroOrderItemInterface
    {
        [$incVat, $exVat] = $this->discountAmountResolver->resolve(
            $address,
            $this->getLineVatRates($quote),
            (int)$quote->getStoreId()
        );

        return $this->discountLines->orderLevelLine(DiscountLines::ORDER_REFERENCE, $incVat, $exVat);
    }

    /**
     * The discount the lines leave out, so the customer is never charged more than Magento's total
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @param Quote $quote
     * @param QliroOrderItemInterface[] $discountLines
     * @return QliroOrderItemInterface|null
     */
    private function getMissingDiscount($address, Quote $quote, array $discountLines): ?QliroOrderItemInterface
    {
        [$totalIncVat, $totalExVat] = $this->discountAmountResolver->resolve(
            $address,
            $this->getLineVatRates($quote),
            (int)$quote->getStoreId()
        );

        $sent = 0.0;

        foreach ($discountLines as $discountLine) {
            $sent += abs($discountLine->getPricePerItemIncVat()) * $discountLine->getQuantity();
        }

        $missing = round($totalIncVat - $sent, 2);

        if ($missing < 0.01) {
            if ($missing <= -0.01) {
                $this->logManager->warning(
                    'The discount lines add up to more than the cart discount',
                    ['extra' => ['quote_id' => $quote->getId(), 'discount' => $totalIncVat, 'lines' => $sent]]
                );
            }

            return null;
        }

        $exVat = $totalIncVat > 0 ? round($missing * $totalExVat / $totalIncVat, 2) : $missing;

        return $this->discountLines->orderLevelLine(DiscountLines::ORDER_REFERENCE, $missing, $exVat);
    }

    /**
     * The VAT rates of the lines the discount is spread over, so the resolver can tell a discount
     * VAT the cart could have produced from one it could not. An upper bound is all it needs, so
     * the children of a configurable or a bundle are left in.
     *
     * @param Quote $quote
     * @return float[]
     */
    private function getLineVatRates(Quote $quote): array
    {
        return array_map(
            static fn($item): float => (float)$item->getTaxPercent(),
            $quote->getAllItems()
        );
    }
}

<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;

/**
 * The discount lines a capture takes; an order level line cannot be split, so it waits for a capture that stays positive
 */
final class AppliedRulesHandler implements OrderItemHandlerInterface
{
    /**
     * Set on the order by the builder when this capture leaves nothing of the order to capture
     */
    public const FINAL_CAPTURE_FLAG = 'qliro_final_capture';

    /**
     * The order level lines a capture before 1.8.11 sent, for the payment to add to its count
     */
    public const SENT_BEFORE_RECORDING = 'qliro_discount_sent_before_recording';

    private const DISCOUNT_REFERENCE_PREFIX = 'DSC';
    private const DEFAULT_DISCOUNT_REFERENCE = 'DSC_ORDER_DISCOUNT';

    /**
     * @param DiscountAmountResolver $discountAmountResolver
     * @param DiscountLines $discountLines
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly DiscountAmountResolver $discountAmountResolver,
        private readonly DiscountLines          $discountLines,
        private readonly LogManager             $logManager
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function handle($orderItems, $order): array
    {
        if (!$order instanceof Order || $order->getPayment() === null) {
            return $orderItems;
        }

        $payment = $order->getPayment();
        $reservation = $payment->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT);
        $isLegacy = !is_array($reservation);

        if ($isLegacy) {
            $reservation = $this->getLegacyReservation($order);
        }

        $discounts = $reservation['discounts'] ?? [];

        if (!$discounts) {
            return $orderItems;
        }

        $products = $reservation['products'] ?? [];
        $recorded = $payment->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_CAPTURED_UNITS);
        // Nothing recorded on a later capture: an older release sent the line with the first one
        $capturedBeforeRecording = $isLegacy && !is_array($recorded) && !$order->getFirstCaptureFlag();
        // Read only, the payment records what Qliro accepted, see Management\Payment
        $captured = is_array($recorded) ? $recorded : [];
        $added = [];

        foreach ($orderItems as $orderItem) {
            if ($orderItem->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
                continue;
            }

            $reference = $orderItem->getMerchantReference();
            $quantity = (int)$orderItem->getQuantity();
            $captured[$reference] = ($captured[$reference] ?? 0) + $quantity;

            $lineReference = $this->discountLines->lineReference($reference);

            if (isset($discounts[$lineReference])) {
                $units = min($quantity, $discounts[$lineReference]['quantity'] - ($captured[$lineReference] ?? 0));

                if ($units > 0) {
                    $added[] = $this->discountLines->fromReservation($lineReference, $discounts[$lineReference], $units);
                    $captured[$lineReference] = ($captured[$lineReference] ?? 0) + $units;
                }
            }

            $remainderReference = $this->discountLines->remainderReference($reference);

            if (isset($discounts[$remainderReference])
                && empty($captured[$remainderReference])
                && $captured[$reference] >= ($products[$reference] ?? PHP_INT_MAX)
            ) {
                $added[] = $this->discountLines->fromReservation($remainderReference, $discounts[$remainderReference], 1);
                $captured[$remainderReference] = 1;
            }
        }

        $total = 0.0;

        foreach (array_merge($orderItems, $added) as $line) {
            $total += $line->getPricePerItemIncVat() * $line->getQuantity();
        }

        $isFinal = (bool)$order->getData(self::FINAL_CAPTURE_FLAG);

        foreach ($discounts as $reference => $discount) {
            if (($discount['scope'] ?? null) !== DiscountLines::SCOPE_ORDER || !empty($captured[$reference])) {
                continue;
            }

            if ($capturedBeforeRecording) {
                $order->setData(
                    self::SENT_BEFORE_RECORDING,
                    [$reference => $discount['quantity']] + (array)$order->getData(self::SENT_BEFORE_RECORDING)
                );
                continue;
            }

            $amount = $discount['inc'] * $discount['quantity'];

            if ($total + $amount < 0.01 && !$isFinal) {
                $this->logManager->info(
                    'Order level discount line kept for a later capture, this one would turn negative with it',
                    ['extra' => ['order_id' => $order->getId(), 'reference' => $reference, 'capture_total' => $total]]
                );
                continue;
            }

            $added[] = $this->discountLines->fromReservation($reference, $discount, $discount['quantity']);
            $total += $amount;
        }

        return array_merge($orderItems, $added);
    }

    /**
     * The single discount line an order placed before 1.8.11 was reserved with, built as it was then
     *
     * @param Order $order
     * @return array
     */
    private function getLegacyReservation(Order $order): array
    {
        if (abs((float)$order->getDiscountAmount()) <= DiscountAmountResolver::EPSILON) {
            return [];
        }

        [$discountInclVat, $discountExclVat] = $this->discountAmountResolver->resolve(
            $order,
            $this->getLineVatRates($order),
            (int)$order->getStoreId(),
            $this->reservationCarriesDiscountVat($order)
        );

        $reference = $this->getMerchantReference($order);
        $line = $this->discountLines->orderLevelLine($reference, $discountInclVat, $discountExclVat);

        return [
            'products' => [],
            'discounts' => [
                $reference => [
                    'inc' => $line->getPricePerItemIncVat(),
                    'ex' => $line->getPricePerItemExVat(),
                    'vat' => $line->getVatRate(),
                    'quantity' => 1,
                    'scope' => DiscountLines::SCOPE_ORDER,
                ],
            ],
        ];
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
        return (bool)$order->getPayment()->getAdditionalInformation(
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

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * How much of a refund falls under each VAT rate, inc VAT and to the öre
 */
class RefundVatSplit
{
    /**
     * @param Creditmemo $creditMemo
     * @return array<int, array{vat_rate: float, amount: float}> Summing to the memo's grand total
     */
    public function forCreditMemo(Creditmemo $creditMemo): array
    {
        return $this->spread($this->weights($creditMemo), abs((float)$creditMemo->getGrandTotal()));
    }

    /**
     * Spread an amount over the rates of a split in proportion to it
     *
     * @param array<int, array{vat_rate: float, amount: float}> $split
     * @param float $amount
     * @return array<int, array{vat_rate: float, amount: float}>
     */
    public function apportion(array $split, float $amount): array
    {
        return $this->spread($this->weightsOf($split), abs($amount));
    }

    /**
     * Split each amount in turn from what is left, so the rates add up to the split over all of them
     *
     * @param array<int, array{vat_rate: float, amount: float}> $split
     * @param float[] $amounts
     * @return array<int, array<int, array{vat_rate: float, amount: float}>> One split per amount, same keys
     */
    public function apportionEach(array $split, array $amounts): array
    {
        $left = $this->weightsOf($split);
        $splits = [];

        foreach ($amounts as $key => $amount) {
            $splits[$key] = $this->spread($left, abs((float)$amount));

            foreach ($splits[$key] as $entry) {
                $this->add($left, $entry['vat_rate'], -$entry['amount']);
            }
        }

        return $splits;
    }

    /**
     * @param Creditmemo $creditMemo
     * @return array<int|string, float> Rate key => amount inc VAT
     */
    private function weights(Creditmemo $creditMemo): array
    {
        $weights = [];
        $itemDiscount = 0.0;
        $itemCompensation = 0.0;

        foreach ($creditMemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();

            if ($orderItem->isDummy() || (float)$item->getQty() <= 0) {
                continue;
            }

            $rate = $this->itemRate($orderItem->getTaxPercent(), $item);

            $itemDiscount += (float)$item->getDiscountAmount();
            $itemCompensation += (float)$item->getDiscountTaxCompensationAmount();

            $this->add($weights, $rate, (float)$item->getRowTotal() + (float)$item->getTaxAmount()
                + (float)$item->getDiscountTaxCompensationAmount() - (float)$item->getDiscountAmount());
        }

        $this->addShipping($weights, $creditMemo, $itemDiscount, $itemCompensation);
        $this->addFee($weights, $creditMemo);

        if (array_filter($weights, static fn(float $weight): bool => $weight > 0)) {
            return $weights;
        }

        // Nothing to weigh by (adjustment only): the order's one rate, else none
        $rates = [];

        foreach ($creditMemo->getOrder()->getAllItems() as $orderItem) {
            $untaxed = $orderItem->getTaxPercent() === null && (float)$orderItem->getTaxAmount() <= 0;

            if (!$orderItem->isDummy() && !$untaxed) {
                $rates[$this->key($this->itemRate($orderItem->getTaxPercent(), $orderItem))] = true;
            }
        }

        return count($rates) === 1 ? [array_key_first($rates) => 1.0] : ['0' => 1.0];
    }

    /**
     * The tax percent, or the rate the line's amounts after discount imply where the store left none
     *
     * @param mixed $taxPercent
     * @param \Magento\Sales\Model\Order\Creditmemo\Item|\Magento\Sales\Model\Order\Item $line
     * @return float
     */
    private function itemRate($taxPercent, $line): float
    {
        if ($taxPercent !== null || (float)$line->getTaxAmount() <= 0) {
            return (float)$taxPercent;
        }

        $exVat = (float)$line->getRowTotal() - (float)$line->getDiscountAmount()
            + (float)$line->getDiscountTaxCompensationAmount();

        return (new LineVatRate())->fromPrices($exVat + (float)$line->getTaxAmount(), $exVat);
    }

    /**
     * @param array<int|string, float> $weights
     * @param Creditmemo $creditMemo
     * @param float $itemDiscount
     * @param float $itemCompensation
     * @return void
     */
    private function addShipping(
        array &$weights,
        Creditmemo $creditMemo,
        float $itemDiscount,
        float $itemCompensation
    ): void
    {
        $shipping = (float)$creditMemo->getShippingAmount();

        if ($shipping <= 0) {
            return;
        }

        $order = $creditMemo->getOrder();
        $orderShipping = (float)$order->getShippingAmount();
        $orderShippingInclTax = $order->getShippingInclTax() !== null
            ? (float)$order->getShippingInclTax()
            : $orderShipping + (float)$order->getShippingTaxAmount();

        // The memo's totals less what its lines carry are shipping's, Magento keeps no own field for them
        $shippingDiscount = max(0.0, abs((float)$creditMemo->getDiscountAmount()) - $itemDiscount);
        $shippingCompensation = max(0.0, (float)$creditMemo->getDiscountTaxCompensationAmount() - $itemCompensation);

        $this->add(
            $weights,
            (new LineVatRate())->fromPrices($orderShippingInclTax, $orderShipping),
            $shipping + (float)$creditMemo->getShippingTaxAmount() + $shippingCompensation - $shippingDiscount
        );
    }

    /**
     * The invoice fee, which only the memo completing the refund holds
     *
     * @param array<int|string, float> $weights
     * @param Creditmemo $creditMemo
     * @return void
     */
    private function addFee(array &$weights, Creditmemo $creditMemo): void
    {
        $order = $creditMemo->getOrder();

        // Magento counts this memo in total_refunded before the payment refunds it
        if ((float)$order->getTotalRefunded() < (float)$order->getGrandTotal() - 0.005) {
            return;
        }

        $payment = $order->getPayment();
        $fees = $payment instanceof Payment ? $payment->getAdditionalInformation('qliroone_fees') : null;

        if (!is_array($fees) || !$fees) {
            return;
        }

        foreach ($fees as $fee) {
            $incVat = (float)($fee['PricePerItemIncVat'] ?? 0);
            $rate = isset($fee['VatRate'])
                ? (float)$fee['VatRate']
                : (new LineVatRate())->fromPrices($incVat, (float)($fee['PricePerItemExVat'] ?? 0));

            $this->add($weights, $rate, $incVat);
        }
    }

    /**
     * Largest remainder, so the parts add up to the amount to the öre
     *
     * @param array<int|string, float> $weights
     * @param float $amount
     * @return array<int, array{vat_rate: float, amount: float}>
     */
    private function spread(array $weights, float $amount): array
    {
        $weights = array_filter($weights, static fn(float $weight): bool => $weight > 0);
        $cents = (int)round($amount * 100);

        if (!$weights) {
            return [['vat_rate' => 0.0, 'amount' => round($amount, 2)]];
        }

        arsort($weights);
        $total = array_sum($weights);
        $parts = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $exact = $cents * $weight / $total;
            $parts[$key] = (int)floor($exact);
            $remainders[$key] = $exact - $parts[$key];
        }

        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $cents - array_sum($parts)) as $key) {
            $parts[$key]++;
        }

        $split = [];

        foreach ($parts as $key => $part) {
            if ($part > 0 || count($parts) === 1) {
                $split[] = ['vat_rate' => (float)$key, 'amount' => round($part / 100, 2)];
            }
        }

        return $split ?: [['vat_rate' => (float)array_key_first($parts), 'amount' => 0.0]];
    }

    /**
     * @param array<int, array{vat_rate: float, amount: float}> $split
     * @return array<int|string, float>
     */
    private function weightsOf(array $split): array
    {
        $weights = [];

        foreach ($split as $entry) {
            $this->add($weights, (float)$entry['vat_rate'], (float)$entry['amount']);
        }

        return $weights;
    }

    /**
     * @param array<int|string, float> $weights
     * @param float $rate
     * @param float $amount
     * @return void
     */
    private function add(array &$weights, float $rate, float $amount): void
    {
        $key = $this->key($rate);
        $weights[$key] = ($weights[$key] ?? 0.0) + $amount;
    }

    /**
     * A string key, since PHP truncates float array keys to int
     *
     * @param float $rate
     * @return string
     */
    private function key(float $rate): string
    {
        return (string)round($rate, LineVatRate::PRECISION);
    }
}

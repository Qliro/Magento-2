<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterface;
use Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo\RefundVatSplit;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * What each Qliro capture holds under each VAT rate, inc VAT, and how much of it was refunded
 *
 * Qliro books a refund's VAT on the invoice of the capture it reverses, so a refund is split by the
 * rates of the capture it goes to rather than by the credit memo alone. Kept on the payment:
 * captures recorded before this existed have no entry, and their refunds follow the credit memo.
 */
class CaptureVatLedger
{
    /** Payment additional information key */
    public const KEY = 'qliro_capture_vat';

    private const EPSILON = 0.005;

    /**
     * @var LineVatRate
     */
    private LineVatRate $lineVatRate;

    /**
     * @var RefundVatSplit
     */
    private RefundVatSplit $refundVatSplit;

    /**
     * @param LineVatRate|null $lineVatRate
     * @param RefundVatSplit|null $refundVatSplit
     */
    public function __construct(?LineVatRate $lineVatRate = null, ?RefundVatSplit $refundVatSplit = null)
    {
        $this->lineVatRate = $lineVatRate ?? new LineVatRate();
        $this->refundVatSplit = $refundVatSplit ?? new RefundVatSplit();
    }

    /**
     * Record the lines a capture sent, once: a capture adopted again keeps what it first held
     *
     * @param Payment $payment
     * @param string $transactionId Qliro PaymentTransactionId of the capture
     * @param array|null $shipments The capture request's shipments, as the request holds them
     * @return void
     */
    public function recordCapture(Payment $payment, string $transactionId, ?array $shipments): void
    {
        $ledger = $this->read($payment);

        if ($transactionId === '' || isset($ledger['captures'][$transactionId])) {
            return;
        }

        $captured = [];

        foreach ($shipments ?: [] as $shipment) {
            if (!$shipment instanceof QliroShipmentInterface) {
                continue;
            }

            foreach ($shipment->getOrderItems() as $line) {
                $this->add(
                    $captured,
                    $this->rateOf($line),
                    $line->getPricePerItemIncVat() * $line->getQuantity()
                );
            }
        }

        if (!$captured) {
            return;
        }

        $ledger['captures'][$transactionId] = ['captured' => $captured, 'refunded' => []];
        $this->write($payment, $ledger);
    }

    /**
     * What is left to refund on a capture under each rate, null when the capture was never recorded
     *
     * @param Payment $payment
     * @param string $transactionId
     * @return array<int|string, float>|null Rate => amount inc VAT
     */
    public function available(Payment $payment, string $transactionId): ?array
    {
        $capture = $this->read($payment)['captures'][$transactionId] ?? null;

        if (!is_array($capture) || !is_array($capture['captured'] ?? null)) {
            return null;
        }

        $left = [];

        foreach ($capture['captured'] as $rate => $amount) {
            $this->add($left, (float)$rate, (float)$amount);
        }

        foreach ((array)($capture['refunded'] ?? []) as $rate => $amount) {
            $this->add($left, (float)$rate, -(float)$amount);
        }

        return $left;
    }

    /**
     * Note the split of a refund Qliro took, until its outcome comes back
     *
     * @param Payment $payment
     * @param string $refundTransactionId
     * @param string $captureTransactionId
     * @param array<int, array{vat_rate: float, amount: float}> $split
     * @return void
     */
    public function recordSent(Payment $payment, string $refundTransactionId, string $captureTransactionId, array $split): void
    {
        if ($refundTransactionId === '' || !$split) {
            return;
        }

        $ledger = $this->read($payment);
        $ledger['sent'][$refundTransactionId] = ['capture' => $captureTransactionId, 'split' => $split];
        $this->write($payment, $ledger);
    }

    /**
     * Count a confirmed refund against its capture's rates
     *
     * A refund whose split was not noted (sent by an earlier release, or confirmed before the noting
     * was saved) takes the capture's own mix of what is left.
     *
     * @param Payment $payment
     * @param string $refundTransactionId
     * @param string $captureTransactionId
     * @param float $amount
     * @return void
     */
    public function confirm(Payment $payment, string $refundTransactionId, string $captureTransactionId, float $amount): void
    {
        $ledger = $this->read($payment);
        $sent = $ledger['sent'][$refundTransactionId] ?? null;
        unset($ledger['sent'][$refundTransactionId]);

        $capture = $ledger['captures'][$captureTransactionId] ?? null;

        if (is_array($capture)) {
            $split = is_array($sent) ? (array)($sent['split'] ?? []) : [];
            $amount = abs($amount);

            if (abs($this->total($split) - $amount) > self::EPSILON) {
                $left = (array)$this->available($payment, $captureTransactionId);
                $split = $this->refundVatSplit->apportion($this->toSplit($left), $amount);
            }

            $refunded = (array)($capture['refunded'] ?? []);

            foreach ($split as $entry) {
                $this->add($refunded, (float)$entry['vat_rate'], (float)$entry['amount']);
            }

            $ledger['captures'][$captureTransactionId]['refunded'] = $refunded;
        }

        $this->write($payment, $ledger);
    }

    /**
     * Forget a refund Qliro refused
     *
     * @param Payment $payment
     * @param string $refundTransactionId
     * @return void
     */
    public function drop(Payment $payment, string $refundTransactionId): void
    {
        $ledger = $this->read($payment);

        if (!isset($ledger['sent'][$refundTransactionId])) {
            return;
        }

        unset($ledger['sent'][$refundTransactionId]);
        $this->write($payment, $ledger);
    }

    /**
     * The rate the line states, or the one its prices imply where it states none
     *
     * @param QliroOrderItemInterface $line
     * @return float
     */
    private function rateOf(QliroOrderItemInterface $line): float
    {
        $rate = $line->getVatRate();
        $incVat = $line->getPricePerItemIncVat();
        $exVat = $line->getPricePerItemExVat();

        if ($rate <= 0 && abs(abs($incVat) - abs($exVat)) > LineVatRate::EPSILON) {
            return $this->lineVatRate->fromPrices($incVat, $exVat);
        }

        return $rate;
    }

    /**
     * @param array<int|string, float> $amounts
     * @return array<int, array{vat_rate: float, amount: float}>
     */
    private function toSplit(array $amounts): array
    {
        $split = [];

        foreach ($amounts as $rate => $amount) {
            if ($amount > 0) {
                $split[] = ['vat_rate' => (float)$rate, 'amount' => (float)$amount];
            }
        }

        return $split;
    }

    /**
     * @param array $split
     * @return float
     */
    private function total(array $split): float
    {
        $total = 0.0;

        foreach ($split as $entry) {
            $total += (float)($entry['amount'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * @param array<int|string, float> $amounts
     * @param float $rate
     * @param float $amount
     * @return void
     */
    private function add(array &$amounts, float $rate, float $amount): void
    {
        $key = (string)round($rate, LineVatRate::PRECISION);
        $amounts[$key] = round(($amounts[$key] ?? 0.0) + $amount, 2);
    }

    /**
     * @param Payment $payment
     * @return array{captures?: array, sent?: array}
     */
    private function read(Payment $payment): array
    {
        $ledger = $payment->getAdditionalInformation(self::KEY);

        return is_array($ledger) ? $ledger : [];
    }

    /**
     * @param Payment $payment
     * @param array $ledger
     * @return void
     */
    private function write(Payment $payment, array $ledger): void
    {
        $payment->setAdditionalInformation(self::KEY, $ledger);
    }
}

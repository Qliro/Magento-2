<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreditMemoItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;

/**
 * Nothing in the module wires this builder up, it is kept because a store may wire it up on its
 * own, and it matched a credit memo line to an order line by the sku alone (PLIN-408).
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\CreditMemoItemsBuilder
 */
class CreditMemoItemsBuilderTest extends TestCase
{
    /**
     * One sku on two cart lines: each order line takes the quantity of its own credit memo line,
     * where both used to take the quantity of whichever line came first.
     */
    public function testEachLineOfARepeatedSkuTakesItsOwnQuantity(): void
    {
        $lines = $this->build(
            [
                $this->buildLine('518:Kanalplast', 25.0),
                $this->buildLine('519:Kanalplast', 25.0),
            ],
            [
                $this->buildCreditMemoItem('Kanalplast', 518, 10.0),
                $this->buildCreditMemoItem('Kanalplast', 519, 4.0),
            ],
            true
        );

        self::assertSame(10.0, $lines[0]->getQuantity());
        self::assertSame(4.0, $lines[1]->getQuantity());
    }

    /**
     * An order placed before the reference carried the cart item id was reserved with the bare
     * sku, and Qliro refuses a line that disagrees with the reservation.
     */
    public function testAnOrderFromBeforeTheStampKeepsTheBareSku(): void
    {
        $lines = $this->build(
            [$this->buildLine('518:Kanalplast', 25.0)],
            [$this->buildCreditMemoItem('Kanalplast', 518, 25.0)],
            false
        );

        self::assertSame('Kanalplast', $lines[0]->getMerchantReference());
    }

    /**
     * A stamped order keeps the reference this version builds, which is what its reservation holds.
     */
    public function testAStampedOrderKeepsTheReferenceWithTheItemId(): void
    {
        $lines = $this->build(
            [$this->buildLine('518:Kanalplast', 25.0)],
            [$this->buildCreditMemoItem('Kanalplast', 518, 25.0)],
            true
        );

        self::assertSame('518:Kanalplast', $lines[0]->getMerchantReference());
    }

    /**
     * A credit memo line the order line cannot be matched to is not refunded, which is what the
     * sku match did for a line whose product is not on the credit memo.
     */
    public function testALineTheCreditMemoDoesNotHoldIsDropped(): void
    {
        $lines = $this->build(
            [$this->buildLine('518:Kanalplast', 25.0)],
            [$this->buildCreditMemoItem('skylthallare 5mm', 520, 50.0)],
            true
        );

        self::assertSame([], $lines);
    }

    /**
     * @param QliroOrderItemInterface[] $orderLines
     * @param CreditmemoItem[] $creditMemoItems
     * @param bool $stamped
     * @return QliroOrderItemInterface[]
     */
    private function build(array $orderLines, array $creditMemoItems, bool $stamped): array
    {
        $typeResolver = $this->createMock(TypePoolHandler::class);
        $typeResolver->method('resolveQliroOrderItem')
            ->willReturnOnConsecutiveCalls(...$orderLines);

        $quoteSourceProvider = $this->createMock(QuoteSourceProvider::class);
        $quoteSourceProvider->method('generateSourceItem')
            ->willReturn($this->createMock(TypeSourceItemInterface::class));

        $quote = $this->createMock(Quote::class);
        $quote->method('getAllItems')->willReturn(
            array_map(fn(): QuoteItem => $this->createMock(QuoteItem::class), $orderLines)
        );

        $builder = new CreditMemoItemsBuilder(
            $this->createMock(TaxHelper::class),
            $this->createMock(TaxCalculation::class),
            $typeResolver,
            $this->createMock(QliroOrderItemInterfaceFactory::class),
            $this->createMock(QliroHelper::class),
            $quoteSourceProvider,
            $this->createMock(ManagerInterface::class)
        );

        return array_values(
            $builder
                ->setQuote($quote)
                ->setCreditMemo($this->buildCreditMemo($creditMemoItems, $stamped))
                ->create()
        );
    }

    /**
     * @param CreditmemoItem[] $items
     * @param bool $stamped
     * @return Creditmemo
     */
    private function buildCreditMemo(array $items, bool $stamped): Creditmemo
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(
                static fn($key = null) => $key === Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID
                    ? $stamped
                    : null
            );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);

        $creditMemo = $this->createMock(Creditmemo::class);
        $creditMemo->method('getItems')->willReturn($items);
        $creditMemo->method('getOrder')->willReturn($order);

        return $creditMemo;
    }

    private function buildCreditMemoItem(string $sku, int $quoteItemId, float $qty): CreditmemoItem
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('getQuoteItemId')->willReturn($quoteItemId);

        $item = $this->createMock(CreditmemoItem::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getOrderItem')->willReturn($orderItem);

        return $item;
    }

    private function buildLine(string $reference, float $qty): QliroOrderItemInterface
    {
        $line = new Item();
        $line->setMerchantReference($reference);
        $line->setType(QliroOrderItemInterface::TYPE_PRODUCT);
        $line->setQuantity($qty);

        return $line;
    }
}

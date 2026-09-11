<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo as SalesCreditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as SalesCreditmemoItem;
use Qliro\QliroOne\Model\QliroOrder\LineReference;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Magento\Sales\Api\OrderItemRepositoryInterface;

/**
 * QliroOne credit memo items builder class
 */
class CreditMemoItemsBuilder extends OrderItemsBuilder
{
    /**
     * @var CreditmemoInterface
     */
    private $creditMemo;

    /**
     * @var LineReference|null
     */
    private ?LineReference $lineReference = null;

    /**
     * Set credit memo for data extraction
     *
     * @param CreditmemoInterface $creditMemo
     * @return $this
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo)
    {
        $this->creditMemo = $creditMemo;

        return $this;
    }

    /**
     * Create an array of containers
     *
     * @return QliroOrderItemInterface[]
     */
    public function create()
    {
        if (empty($this->creditMemo)) {
            throw new \LogicException('Credit memo entity is not set.');
        }
        $this->handlers = [];
        $items = parent::create();

        if (!count($items)) {
            return $items;
        }

        $creditMemoItems = [];
        foreach ($items as $key => $item) {
            if ($item->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
                $creditMemoItems[$key] = $item;
                continue;
            }

            $creditMemoItem = $this->getCreditMemoItemByReference($item->getMerchantReference());
            if (is_null($creditMemoItem)) {
                continue;
            }

            if (!$creditMemoItem->getQty()) {
                continue;
            }
            $item->setQuantity((int)$creditMemoItem->getQty());
            $creditMemoItems[$key] = $item;
        }

        $order = $this->getOrder();

        // Without the order the stamp cannot be read, and the lines stay as this version builds them
        return $order === null
            ? $creditMemoItems
            : $this->lineReference()->alignWithReservation($creditMemoItems, $order);
    }

    /**
     * Get the credit memo line the given order line reference stands for
     *
     * The reference carries the cart item id, so two lines of the same sku each find their own
     * credit memo line instead of both taking the quantity of the first one. A reference from
     * before 1.7.40 carries the sku alone and still resolves by sku (PLIN-408).
     *
     * @param string $reference
     * @return CreditmemoItemInterface|null
     */
    private function getCreditMemoItemByReference(string $reference)
    {
        $itemId = $this->lineReference()->itemIdOf($reference);
        $sku = $this->lineReference()->skuOf($reference);

        $bySku = null;

        foreach ($this->creditMemo->getItems() as $item) {
            if ($itemId !== null && $this->getQuoteItemId($item) === $itemId) {
                return $item;
            }

            if ($bySku === null && $item->getSku() === $sku) {
                $bySku = $item;
            }
        }

        return $bySku;
    }

    /**
     * The cart item id the given credit memo line goes back to, or null when it cannot be read
     *
     * @param CreditmemoItemInterface $item
     * @return string|null
     */
    private function getQuoteItemId(CreditmemoItemInterface $item): ?string
    {
        if (!$item instanceof SalesCreditmemoItem) {
            return null;
        }

        $orderItem = $item->getOrderItem();
        $quoteItemId = $orderItem ? $orderItem->getQuoteItemId() : null;

        return $quoteItemId === null ? null : (string)$quoteItemId;
    }

    /**
     * The order the credit memo belongs to, or null when the interface cannot hand it over
     *
     * @return Order|null
     */
    private function getOrder(): ?Order
    {
        if (!$this->creditMemo instanceof SalesCreditmemo) {
            return null;
        }

        $order = $this->creditMemo->getOrder();

        return $order instanceof Order ? $order : null;
    }

    /**
     * Lazy because this class declares no constructor of its own, see `OrderItemsBuilder`
     *
     * @return LineReference
     */
    private function lineReference(): LineReference
    {
        if ($this->lineReference === null) {
            $this->lineReference = new LineReference();
        }

        return $this->lineReference;
    }
}

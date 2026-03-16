<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;

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
     * Class constructor
     *
     * @param TaxHelper $taxHelper
     * @param TaxCalculation $taxCalculation
     * @param TypePoolHandler $typeResolver
     * @param QliroHelper $qliroHelper
     * @param QuoteSourceProvider $quoteSourceProvider
     * @param ManagerInterface $eventManager
     * @param $handlers
     */
    public function __construct(
        TaxHelper $taxHelper,
        TaxCalculation $taxCalculation,
        TypePoolHandler $typeResolver,
        QliroHelper $qliroHelper,
        QuoteSourceProvider $quoteSourceProvider,
        ManagerInterface $eventManager,
        $handlers = []
    )
    {
        parent::__construct(
            $taxHelper,
            $taxCalculation,
            $typeResolver,
            $qliroHelper,
            $quoteSourceProvider,
            $eventManager,
            $handlers
        );
    }

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
     * Create an array of order item payloads for a Credit Memo.
     *
     * @return array[]
     */
    public function create()
    {
        if (empty($this->creditMemo)) {
            throw new \LogicException('Credit memo entity is not set.');
        }
        $items = parent::create();

        if (!count($items)) {
            return $items;
        }

        $creditMemoItems = [];
        foreach ($items as $key => $item) {
            // Keep non-product items (shipping/discount/fee etc.) as-is
            if (($item['Type'] ?? null) !== 'Product') {
                $creditMemoItems[$key] = $item;
                continue;
            }

            $merchantRef = (string)($item['MerchantReference'] ?? '');
            // Some implementations may include "id:sku"; prefer SKU for matching.
            $sku = str_contains($merchantRef, ':') ? (string)substr($merchantRef, strrpos($merchantRef, ':') + 1) : $merchantRef;

            $creditMemoItem = $this->getCreditMemoItemBySku($sku);
            if (is_null($creditMemoItem)) {
                continue;
            }

            if (!$creditMemoItem->getQty()) {
                continue;
            }
            // Update quantity for this credit memo
            $item['Quantity'] = (int)$creditMemoItem->getQty();
            $creditMemoItems[$key] = $item;
        }

        return $creditMemoItems;
    }

    /**
     * Get credit memo item by provided product sku
     *
     * @param string $sku
     * @return CreditmemoItemInterface|null
     */
    private function getCreditMemoItemBySku(string $sku)
    {
        $toReturn = null;
        foreach ($this->creditMemo->getItems() as $item) {
            if ($item->getSku() !== $sku) {
                continue;
            }

            $toReturn = $item;
            break;
        }

        return $toReturn;
    }
}

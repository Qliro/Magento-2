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
     * @var OrderItemRepositoryInterface
     */
    private $orderItemRepository;

    /**
     * @param TaxHelper $taxHelper
     * @param TaxCalculation $taxCalculation
     * @param TypePoolHandler $typeResolver
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param QliroHelper $qliroHelper
     * @param QuoteSourceProvider $quoteSourceProvider
     * @param ManagerInterface $eventManager
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param $handlers
     */
    public function __construct(
        TaxHelper $taxHelper,
        TaxCalculation $taxCalculation,
        TypePoolHandler $typeResolver,
        QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        QliroHelper $qliroHelper,
        QuoteSourceProvider $quoteSourceProvider,
        ManagerInterface $eventManager,
        OrderItemRepositoryInterface $orderItemRepository,
        $handlers = []
    )
    {
        parent::__construct(
            $taxHelper,
            $taxCalculation,
            $typeResolver,
            $qliroOrderItemFactory,
            $qliroHelper,
            $quoteSourceProvider,
            $eventManager,
            $handlers
        );
        $this->orderItemRepository = $orderItemRepository;
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
     * Create an array of containers
     *
     * @return QliroOrderItemInterface[]
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
            if ($item->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
                $creditMemoItems[$key] = $item;
            }

            $creditMemoItem = $this->getCreditMemoItemBySku($item->getMerchantReference());
            if (is_null($creditMemoItem)) {
                continue;
            }

            if (!$creditMemoItem->getQty()) {
                continue;
            }
            $item->setQuantity((int)$creditMemoItem->getQty());
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

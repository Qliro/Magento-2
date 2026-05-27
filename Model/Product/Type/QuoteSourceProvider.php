<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Tax\Helper\Data as TaxHelper;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface as TypeSourceItem;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory as TypeSourceItemFactory;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;
use Qliro\QliroOne\Api\Product\ProductNameResolverInterface as ProductNameResolver;
use Qliro\QliroOne\Model\Product\VatRate;

/**
 * Quote Source Provider class
 */
class QuoteSourceProvider implements TypeSourceProviderInterface
{
    private array $sourceItems = [];
    private ?Quote $quote = null;

    /**
     * Class constructor
     *
     * @param ProductPool                      $productPool
     * @param TypeSourceItemFactory            $typeSourceItemFactory
     * @param Config                           $config
     * @param RecurringDataService             $recurringDataService
     * @param ProductNameResolver              $productNameResolver
     * @param TaxHelper                        $taxHelper
     * @param VatRate                          $vatRate
     */
    public function __construct(
        private readonly ProductPool           $productPool,
        private readonly TypeSourceItemFactory $typeSourceItemFactory,
        private readonly Config                $config,
        private readonly RecurringDataService  $recurringDataService,
        private readonly ProductNameResolver   $productNameResolver,
        private readonly TaxHelper             $taxHelper,
        private readonly VatRate               $vatRate
    ) {
    }

    /**
     * @inHeirtDoc
     */
    public function getStoreId(): int
    {
        return (int)$this->quote->getStoreId();
    }

    /**
     * @inHeirtDoc
     */
    public function getSourceItemByMerchantReference(mixed $reference): ?TypeSourceItem
    {
        if (is_array($reference)) {
            if (isset($reference['quoteItems'])) {
                foreach ($reference['quoteItems'] as $ref) {
                    if (str_contains($ref, ':')) {
                        list($quoteItemId, $sku) = explode(':', $ref);
                    } else {
                        $quoteItemId = null;
                        $sku = $reference;
                    }
                }
            }
        } else {
            if (str_contains($reference, ':')) {
                list($quoteItemId, $sku) = explode(':', $reference);
            } else {
                $quoteItemId = null;
                $sku = $reference;
            }
        }

        try {
            $quoteItem = $this->quote->getItemById($quoteItemId);

            if (!$quoteItem) {
                if ($sku) {
                    $product = $this->productPool->getProduct($sku, $this->getStoreId());

                    $quoteItem = $this->quote->getItemByProduct($product);
                } else {
                    $quoteItem = null;
                }
            }

            if ($quoteItem) {
                // Basically, at this point we do not update quote items

                return $this->generateSourceItem($quoteItem, $quoteItem->getQty());
            }

            return null;
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @inHeirtDoc
     */
    public function getSourceItems(): array
    {
        $result = [];

        /** @var Item $item */
        foreach ($this->quote->getAllVisibleItems() as $item) {
            $result[] = $this->generateSourceItem($item, $item->getQty());
        }

        return $result;
    }

    /**
     * Set quote
     *
     * @param Quote|null $quote
     */
    public function setQuote(?Quote $quote): void
    {
        $this->quote = $quote;
    }

    /**
     * @inHeirtDoc
     */
    public function generateSourceItem(mixed $item, float $quantity): TypeSourceItem
    {
        if (!isset($this->sourceItems[$item->getItemId()])) {
            /** @var TypeSourceItem $sourceItem */
            $sourceItem = $this->typeSourceItemFactory->create();

            $sourceItem->setId($item->getItemId());
            $sourceItem->setName($this->productNameResolver->getName($item));

            if ($this->taxHelper->discountTax($item->getStore())) {
                $sourceItem->setPriceInclTax(
                    ($item->getRowTotalInclTax() - $item->getDiscountAmount()) / $quantity
                );
                $sourceItem->setPriceExclTax(
                    ($item->getRowTotalInclTax() - $item->getDiscountAmount() - $item->getTaxAmount()) / $quantity
                );
            } else {
                $sourceItem->setPriceInclTax(
                    ($item->getRowTotal() - $item->getDiscountAmount() + $item->getTaxAmount()) / $quantity
                );
                $sourceItem->setPriceExclTax(
                    ($item->getRowTotal() - $item->getDiscountAmount()) / $quantity
                );
            }

            $sourceItem->setVatRate($this->vatRate->getVatRateForProduct($item));
            $sourceItem->setQty($item->getQty());
            $sourceItem->setSku($item->getSku());
            $sourceItem->setType($item->getProductType());
            $sourceItem->setProduct($item->getProduct());
            $sourceItem->setItem($item);
            $this->setSubscriptionInSourceItem($sourceItem);

            $this->sourceItems[$item->getItemId()] = $sourceItem;

            if ($parentItem = $item->getParentItem()) {
                $sourceItem->setParent($this->generateSourceItem($parentItem, $quantity));
            }
        }

        return $this->sourceItems[$item->getItemId()];
    }

    /**
     * Sets a subscription flag in the source item if it has been set as enabled in quote payment
     *
     * @param TypeSourceItem $sourceItem
     * @return void
     */
    private function setSubscriptionInSourceItem(TypeSourceItem $sourceItem): void
    {
        if (!$this->config->isUseRecurring()) {
            return;
        }

        $recurringInfo = $this->recurringDataService->quoteGetter($this->quote);
        $sourceItem->setSubscription(!!$recurringInfo->getEnabled());
    }
}

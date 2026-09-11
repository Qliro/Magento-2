<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\Quote\Item\AbstractItem as QuoteItem;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory as QliroOrderItemFactory;
use Qliro\QliroOne\Api\Product\TypeHandlerInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Api\StockAvailabilityInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;
use Qliro\QliroOne\Model\Stock\QuoteLines;

/**
 * Default product type handler class
 */
class DefaultHandler implements TypeHandlerInterface
{
    /**
     * @var LineVatRate
     */
    private readonly LineVatRate $lineVatRate;

    /**
     * @var StockAvailabilityInterface|null
     */
    private ?StockAvailabilityInterface $stockAvailability;

    /**
     * @var QuoteLines
     */
    private readonly QuoteLines $quoteLines;

    /**
     * Class constructor
     *
     * @param QliroOrderItemFactory            $qliroOrderItemFactory
     * @param Data                             $qliroHelper
     * @param Config                           $config
     * @param VatRate                          $vatRate
     * @param LineVatRate|null                 $lineVatRate
     * @param StockAvailabilityInterface|null  $stockAvailability
     * @param QuoteLines|null                  $quoteLines
     */
    public function __construct(
        private readonly QliroOrderItemFactory $qliroOrderItemFactory,
        private readonly Data                  $qliroHelper,
        private readonly Config                $config,
        private readonly VatRate               $vatRate,
        ?LineVatRate                           $lineVatRate = null,
        ?StockAvailabilityInterface            $stockAvailability = null,
        ?QuoteLines                            $quoteLines = null
    ) {
        // Optional so a store's handler calling parent::__construct() with the old signature keeps working
        $this->lineVatRate = $lineVatRate ?? new LineVatRate();
        $this->stockAvailability = $stockAvailability;
        $this->quoteLines = $quoteLines ?? new QuoteLines();
    }

    /**
     * @inheirtDoc
     */
    public function getQliroOrderItem(TypeSourceItemInterface $item)
    {
        $pricePerItemIncVat = $this->preparePrice($item);
        $pricePerItemExVat = $this->preparePrice($item, false);

        $qliroOrderItem = $this->qliroOrderItemFactory->create();
        $qliroOrderItem->setMerchantReference($item->getSku());
        $qliroOrderItem->setType(QliroOrderItemInterface::TYPE_PRODUCT);
        $qliroOrderItem->setQuantity($this->prepareQuantity($item));
        $qliroOrderItem->setPricePerItemIncVat((float)$this->qliroHelper->formatPrice($pricePerItemIncVat));
        $qliroOrderItem->setPricePerItemExVat((float)$this->qliroHelper->formatPrice($pricePerItemExVat));
        $qliroOrderItem->setVatRate($this->resolveVatRate($item, (float)$pricePerItemIncVat, (float)$pricePerItemExVat));
        $qliroOrderItem->setDescription($this->prepareDescription($item));
        $qliroOrderItem->setMetaData($this->prepareMetaData($item));

        return $qliroOrderItem;
    }

    /**
     * Resolve the VAT rate for a line.
     *
     * Prefer the tax percent Magento already calculated on the quote item (taken from the
     * configurable parent when present, like the discount below). That value uses the real
     * customer address, unlike the store-default tax lookup which can resolve to 0 depending
     * on tax configuration. An explicit 0 there is a statement, a tax exempt customer, and is
     * sent as it stands. Without a tax percent the two prices decide, see `LineVatRate`, equal
     * prices included: they hold no VAT. Only a line with no price at all says nothing about
     * itself, and there the store calculation is the last resort.
     *
     * @param TypeSourceItemInterface $item
     * @param float $incVat
     * @param float $exVat
     * @return float
     */
    private function resolveVatRate(TypeSourceItemInterface $item, float $incVat, float $exVat): float
    {
        $sourceItem = $item->getParent() ? $item->getParent()->getItem() : $item->getItem();
        $taxPercent = $sourceItem ? $sourceItem->getTaxPercent() : null;

        if ($taxPercent !== null && $taxPercent !== '') {
            return (float)$taxPercent;
        }

        if (abs($exVat) > LineVatRate::EPSILON) {
            return $this->lineVatRate->fromPrices($incVat, $exVat);
        }

        return $this->vatRate->getVatRateForProduct($item);
    }

    /**
     * @inheirtDoc
     */
    public function getItem(
        QliroOrderItemInterface $qliroOrderItem,
        TypeSourceProviderInterface $typeSourceProvider
    ) {
        if ($qliroOrderItem->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem->getMetadata());
    }

    /**
     * @inheirtDoc
     */
    public function prepareMerchantReference(TypeSourceItemInterface $item)
    {
        return sprintf('%s:%s', $item->getId(), $item->getSku());
    }

    /**
     * @inheirtDoc
     */
    public function preparePrice(TypeSourceItemInterface $item, $taxIncluded = true)
    {
        return $taxIncluded ? $item->getPriceInclTax() : $item->getPriceExclTax();
    }

    /**
     * @inheirtDoc
     */
    public function prepareQuantity(TypeSourceItemInterface $item)
    {
        return $item->getQty();
    }

    /**
     * @inheirtDoc
     */
    public function prepareDescription(TypeSourceItemInterface $item)
    {
        return $item->getName();
    }

    /**
     * @inheirtDoc
     */
    public function prepareMetaData(TypeSourceItemInterface $item)
    {
        $meta = [
            'qliro' => 'checkout'
        ];
        if ($item->getSubscription()) {

            $meta = [
                'Subscription' => [
                    'Enabled' => true
                ]
            ];
        }

        $meta['quoteItems'] = [
            $this->prepareMerchantReference($item) => $this->prepareMerchantReference($item),
        ];

        $product = $item->getProduct();
        if ($this->config->isIngridEnabled($product->getStoreId())) {
            //if($meta == null) {
            //    $meta = [];
            //}
            $meta['Ingrid'] = [
                'Weight' => intval($product->getWeight() * 1000),
                'Sku' => $product->getSku(),
                'Attributes' => [],
                'Dimensions' => [//TODO: Create dimensions attributes
                    'Height' => 0,
                    'Length' => 0,
                    'Width' => 0
                ],
                'OutOfStock' => !$this->isSalable($item, $product),
                'Discount' => $item->getParent() ?
                    intval($item->getParent()->getItem()->getDiscountAmount() * 100) :
                    intval($item->getItem()->getDiscountAmount() * 100)
            ];
            return $meta;

        }
        return $meta;
    }

    /**
     * Tell whether the line can be sold in the quantity it asks for
     *
     * The same reader the validate callback uses, so what Qliro is told about a line here and what
     * the callback decides about it later cannot disagree. A cart line is counted the way Magento
     * counts it, `getTotalQty()`, which is the number the callback asks about: a child line's own
     * quantity times its parent's, one per parent for a configurable and per bundle for a bundle.
     * An order line already carries the quantity ordered, so there the handler's own says it.
     *
     * @param TypeSourceItemInterface $item
     * @param Product $product
     * @return bool
     */
    private function isSalable(TypeSourceItemInterface $item, Product $product): bool
    {
        $sourceItem = $item->getItem();
        $quote = $this->resolveQuote($sourceItem);
        $websiteId = $this->resolveWebsiteId($quote, $product);

        /*
         * Website 0 is the admin scope, which sells nothing and on an inventory store is no sales
         * channel at all. An order line lands there, its product is loaded without a store, and its
         * stock is not a question worth asking: it was sold when the order was placed.
         */
        if ($websiteId === 0) {
            return true;
        }

        // Resolved on demand, so a handler built with the old signature only reaches for the
        // object manager on an Ingrid store, which is the only place the flag is asked for
        $this->stockAvailability ??= ObjectManager::getInstance()->get(StockAvailabilityInterface::class);

        if ($quote !== null) {
            return $this->answerForCart($quote, $websiteId)[$item->getSku()] ?? true;
        }

        return $this->stockAvailability->isSalable(
            $item->getSku(),
            (float)$this->prepareQuantity($item),
            $item->getType(),
            $websiteId
        );
    }

    /**
     * Answer the whole cart at once
     *
     * A line on its own cannot ask the right question: the same sku can sit on two lines, and the
     * cart asks the stock for both together, which is what the validate callback asks about. The
     * reader answers a question it has already been asked from its own last answer, so asking it
     * once per line costs one round trip for the cart.
     *
     * @param mixed $quote
     * @param int $websiteId
     * @return bool[]
     */
    private function answerForCart($quote, int $websiteId): array
    {
        return $this->stockAvailability->areSalable($this->quoteLines->fromQuote($quote), $websiteId);
    }

    /**
     * Find the cart the line belongs to, if it belongs to one
     *
     * An address item reaches its cart through its address, and an item detached from either has
     * no cart to give rather than an exception out of the order build.
     *
     * @param mixed $sourceItem
     * @return mixed
     */
    private function resolveQuote($sourceItem)
    {
        if (!$sourceItem instanceof QuoteItem) {
            return null;
        }

        try {
            return $sourceItem->getQuote();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Find the website the line is sold on
     *
     * A cart line is sold on the website of its quote, the same one the validate callback asks
     * about. Anything else falls back to the store the product was loaded in, and a store that is
     * gone leaves nothing to ask about rather than an exception out of the order build.
     *
     * @param mixed $quote
     * @param Product $product
     * @return int
     */
    private function resolveWebsiteId($quote, Product $product): int
    {
        try {
            $store = $quote !== null ? $quote->getStore() : $product->getStore();
        } catch (\Throwable $exception) {
            return 0;
        }

        return $store ? (int)$store->getWebsiteId() : 0;
    }
}

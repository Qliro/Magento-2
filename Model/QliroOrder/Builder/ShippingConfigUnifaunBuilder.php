<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Qliro\QliroOne\Model\Formatter\PriceFormatter;
use Qliro\QliroOne\Model\Config;

/**
 * Shipping Config Unifaun Builder class
 */
class ShippingConfigUnifaunBuilder
{
    const UNIFAUN_TAGS_SETTING_TAG = 'tag';
    const UNIFAUN_TAGS_SETTING_FUNC = 'func';
    const UNIFAUN_TAGS_SETTING_VALUE = 'value';

    const UNIFAUN_TAGS_FUNC_BULKY = 'bulky';
    const UNIFAUN_TAGS_FUNC_CARTPRICE = 'cartprice';
    const UNIFAUN_TAGS_FUNC_USERDEFINED = 'userdefined';
    const UNIFAUN_TAGS_FUNC_WEIGHT = 'weight';

    private ?Quote $quote = null;

    /**
     * Class constructor
     *
     * @param ManagerInterface $eventManager
     * @param Config $qliroConfig
     * @param PriceFormatter $priceFormatter
     */
    public function __construct(
        private readonly ManagerInterface $eventManager,
        private readonly Config           $qliroConfig,
        private readonly PriceFormatter   $priceFormatter
    ) {
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return $this
     */
    public function setQuote(Quote $quote): static
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Create a QliroOne order shipping Config container
     *
     * @return array
     */
    public function create(): array
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        $container = [
            'CheckoutId' => $this->qliroConfig->getUnifaunCheckoutId(),
            'Tags' => $this->buildTags($this->qliroConfig->getUnifaunParameters()),
        ];

        $this->eventManager->dispatch(
            'qliroone_shipping_config_unifaun_build_after',
            [
                'quote' => $this->quote,
                'container' => &$container,
            ]
        );

        $this->quote = null;

        return $container;
    }

    /**
     * Should get rewritten for easier customizations
     * @param array $params
     * @return array|null
     */
    private function buildTags(array $params): ?array
    {
        $tags = null;
        foreach ($params as $param) {
            switch ($param[self::UNIFAUN_TAGS_SETTING_FUNC]) {
                case self::UNIFAUN_TAGS_FUNC_BULKY:
                    $tags[$param[self::UNIFAUN_TAGS_SETTING_TAG]] =
                        $this->calculateQuoteBulky($param[self::UNIFAUN_TAGS_SETTING_VALUE]);
                    break;
                case self::UNIFAUN_TAGS_FUNC_USERDEFINED:
                    $tags[$param[self::UNIFAUN_TAGS_SETTING_TAG]] = $param[self::UNIFAUN_TAGS_SETTING_VALUE];
                    break;
                case self::UNIFAUN_TAGS_FUNC_WEIGHT:
                    $tags[$param[self::UNIFAUN_TAGS_SETTING_TAG]] =
                        $this->calculateQuoteWeight($param[self::UNIFAUN_TAGS_SETTING_VALUE]);
                    break;
                case self::UNIFAUN_TAGS_FUNC_CARTPRICE:
                    $tags[$param[self::UNIFAUN_TAGS_SETTING_TAG]] =
                        $this->calculateQuoteCartPrice($param[self::UNIFAUN_TAGS_SETTING_VALUE]);
                    break;
            }
        }

        return $tags;
    }

    private function calculateQuoteBulky(mixed $attributeCode): bool
    {
        $isBulky = false;
        /** @var Item $item */
        foreach ($this->quote->getAllVisibleItems() as $item) {
            if ($this->isItemVirtual($item)) {
                continue;
            }
            $product = $item->getProduct();
            $bulky = $product->getData($attributeCode);
            if ($bulky) {
                $isBulky = true;
                break;
            }
        }

        return $isBulky;
    }

    private function calculateQuoteWeight(mixed $attributeCode): float|int
    {
        $totalWeight = 0;
        /** @var Item $item */
        foreach ($this->quote->getAllVisibleItems() as $item) {
            if ($this->isItemVirtual($item)) {
                continue;
            }
            $product = $item->getProduct();
            $weight = $product->getData($attributeCode);
            if ($weight > 0) {
                $totalWeight += $weight;
            }
        }

        return $totalWeight;
    }

    /**
     * Virtual items don't ship and must not influence Unifaun tags (weight / bulky / etc.),
     * otherwise mixed virtual+physical carts can confuse nShift's shipping-option matching.
     */
    private function isItemVirtual(Item $item): bool
    {
        if ($item->getIsVirtual()) {
            return true;
        }
        $product = $item->getProduct();
        return $product && $product->getIsVirtual();
    }

    private function calculateQuoteCartPrice(mixed $attributeCode): mixed
    {
        $totalAmount = $this->priceFormatter->format($this->quote->getData($attributeCode));

        return $totalAmount;
    }
}

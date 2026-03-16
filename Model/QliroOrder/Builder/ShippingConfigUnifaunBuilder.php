<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Helper\Data;
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

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * Class constructor
     *
     * @param ManagerInterface $eventManager
     * @param Config $qliroConfig
     * @param Data $qliroHelper
     */
    public function __construct(
        private readonly ManagerInterface $eventManager,
        private readonly Config $qliroConfig,
        private readonly Data $qliroHelper
    ) {
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return $this
     */
    public function setQuote(Quote $quote)
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Create a QliroOne order shipping Config container
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigUnifaunInterface
     */
    public function create()
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

    /** Should get rewritten for easier customizations
     * @param array $params
     */
    private function buildTags($params)
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

    /**
     * @param $attributeCode
     */
    private function calculateQuoteBulky($attributeCode)
    {
        $isBulky = false;
        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($this->quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $bulky = $product->getData($attributeCode);
            if ($bulky) {
                $isBulky = true;
                break;
            }
        }

        return $isBulky;
    }

    private function calculateQuoteWeight($attributeCode)
    {
        $totalWeight = 0;
        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($this->quote->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $weight = $product->getData($attributeCode);
            if ($weight > 0) {
                $totalWeight += $weight;
            }
        }

        return $totalWeight;
    }

    private function calculateQuoteCartPrice($attributeCode)
    {
        $totalAmount = $this->qliroHelper->formatPrice($this->quote->getData($attributeCode));

        return $totalAmount;
    }
}

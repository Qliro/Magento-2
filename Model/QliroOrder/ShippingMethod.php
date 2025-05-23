<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterface;

/**
 * QliroOne order shipping method class
 */
class ShippingMethod implements QliroOrderShippingMethodInterface
{
    /**
     * @var string
     */
    private $merchantReference;

    /**
     * @var string
     */
    private $displayName;

    /**
     * @var float
     */
    private $priceIncVat;

    /**
     * @var float
     */
    private $priceExVat;

    /**
     * @var float
     */
    private $originalPrice;

    /**
     * @var array
     */
    private $descriptions;

    /**
     * @var string
     */
    private $brand;

    /**
     * @var bool
     */
    private $supportsAccessCode;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderShippingMethodOptionInterface[]
     */
    private $secondaryOptions;

    /**
     * @var string
     */
    private $shippingFeeMerchantReference;

    /**
     * @var bool
     */
    private $supportsDynamicSecondaryOptions;

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantReference()
    {
        return $this->merchantReference;
    }

    /**
     * @param string $merchantReference
     * @return $this
     */
    public function setMerchantReference($merchantReference)
    {
        $this->merchantReference = $merchantReference;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getDisplayName()
    {
        return $this->displayName;
    }

    /**
     * @param string $displayName
     * @return $this
     */
    public function setDisplayName($displayName)
    {
        $this->displayName = $this->shortenIfTooLong(
            strval($displayName),
            self::MAX_LENGTH_DISPLAY_NAME
        );

        return $this;
    }

    /**
     * Getter.
     *
     * @return float
     */
    public function getPriceIncVat()
    {
        return $this->priceIncVat;
    }

    /**
     * @param float $priceIncVat
     * @return $this
     */
    public function setPriceIncVat($priceIncVat)
    {
        $this->priceIncVat = $priceIncVat;

        return $this;
    }

    /**
     * Getter.
     *
     * @return float
     */
    public function getPriceExVat()
    {
        return $this->priceExVat;
    }

    /**
     * @param float $priceExVat
     * @return $this
     */
    public function setPriceExVat($priceExVat)
    {
        $this->priceExVat = $priceExVat;

        return $this;
    }

    /**
     * Getter.
     *
     * @return float
     */
    public function getOriginalPrice()
    {
        return $this->originalPrice;
    }

    /**
     * @param float $originalPrice
     * @return $this
     */
    public function setOriginalPrice($originalPrice)
    {
        $this->originalPrice = $originalPrice;

        return $this;
    }

    /**
     * Getter.
     *
     * @return array
     */
    public function getDescriptions()
    {
        return $this->descriptions;
    }

    /**
     * @param array $descriptions
     * @return $this
     */
    public function setDescriptions($descriptions)
    {
        if (is_array($descriptions) && count($descriptions)) {
            foreach (array_slice($descriptions, 0, 3) as $key => $description) {
                $descriptions[$key] = $this->shortenIfTooLong(
                    strval($description),
                    self::MAX_LENGTH_DESCRIPTION
                );
            }
        }

        $this->descriptions = $descriptions;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getBrand()
    {
        return $this->brand;
    }

    /**
     * @param string $brand
     * @return $this
     */
    public function setBrand($brand)
    {
        $this->brand = $this->shortenIfTooLong(
            strval($brand),
            self::MAX_LENGTH_BRAND
        );

        return $this;
    }

    /**
     * Getter.
     *
     * @return bool
     */
    public function getSupportsAccessCode()
    {
        return $this->supportsAccessCode;
    }

    /**
     * @param bool $supportsAccessCode
     * @return $this
     */
    public function setSupportsAccessCode($supportsAccessCode)
    {
        $this->supportsAccessCode = $supportsAccessCode;

        return $this;
    }

    /**
     * Getter.
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingMethodOptionInterface[]
     */
    public function getSecondaryOptions()
    {
        return $this->secondaryOptions;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderShippingMethodOptionInterface[] $secondaryOptions
     * @return $this
     */
    public function setSecondaryOptions($secondaryOptions)
    {
        $this->secondaryOptions = $secondaryOptions;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getShippingFeeMerchantReference()
    {
        return $this->shippingFeeMerchantReference;
    }

    /**
     * @param string $shippingFeeMerchantReference
     * @return $this
     */
    public function setShippingFeeMerchantReference($shippingFeeMerchantReference)
    {
        $this->shippingFeeMerchantReference = $shippingFeeMerchantReference;

        return $this;
    }

    /**
     * Getter.
     *
     * @return bool
     */
    public function getSupportsDynamicSecondaryOptions()
    {
        return $this->supportsDynamicSecondaryOptions;
    }

    /**
     * @param bool $supportsDynamicSecondaryOptions
     * @return $this
     */
    public function setSupportsDynamicSecondaryOptions($supportsDynamicSecondaryOptions)
    {
        $this->supportsDynamicSecondaryOptions = $supportsDynamicSecondaryOptions;

        return $this;
    }

    /**
     * @param string $string The input string to be checked and potentially shortened.
     * @param int $maxLength The maximum allowed length for the string, including any suffix.
     * @return string The string shortened to the maximum length if it exceeds the limit, otherwise the original string.
     */
    protected function shortenIfTooLong(string $string, int $maxLength): string
    {
        if (strlen($string) <= $maxLength) {
            return $string;
        }

        return substr(
                $string,
                0,
                $maxLength - strlen(self::MAX_LENGTH_SUFFIX)
            ) . self::MAX_LENGTH_SUFFIX;
    }
}

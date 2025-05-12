<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOne Order Shipping Method interface
 *
 * @api
 */
interface QliroOrderShippingMethodInterface extends ContainerInterface
{
    const MAX_LENGTH_DISPLAY_NAME = 100;

    const MAX_LENGTH_DESCRIPTION = 200;

    const MAX_LENGTH_BRAND = 50;

    const MAX_LENGTH_SUFFIX = '...';

    /**
     * @return string
     */
    public function getMerchantReference();

    /**
     * @return string
     */
    public function getDisplayName();

    /**
     * @return float
     */
    public function getPriceIncVat();

    /**
     * @return float
     */
    public function getPriceExVat();

    /**
     * @return float
     */
    public function getOriginalPrice();

    /**
     * @return array
     */
    public function getDescriptions();

    /**
     * @return string
     */
    public function getBrand();

    /**
     * @return bool
     */
    public function getSupportsAccessCode();

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingMethodOptionInterface[]
     */
    public function getSecondaryOptions();

    /**
     * @return string
     */
    public function getShippingFeeMerchantReference();

    /**
     * @return bool
     */
    public function getSupportsDynamicSecondaryOptions();

    /**
     * @param string $merchantReference
     * @return $this
     */
    public function setMerchantReference($merchantReference);

    /**
     * @param string $displayName
     * @return $this
     */
    public function setDisplayName($displayName);

    /**
     * @param float $priceIncVat
     * @return $this
     */
    public function setPriceIncVat($priceIncVat);

    /**
     * @param float $priceExVat
     * @return $this
     */
    public function setPriceExVat($priceExVat);

    /**
     * @param float $originalPrice
     * @return $this
     */
    public function setOriginalPrice($originalPrice);

    /**
     * @param array $descriptions
     * @return $this
     */
    public function setDescriptions($descriptions);

    /**
     * @param string $brand
     * @return $this
     */
    public function setBrand($brand);

    /**
     * @param bool $supportsAccessCode
     * @return $this
     */
    public function setSupportsAccessCode($supportsAccessCode);

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderShippingMethodOptionInterface[] $secondaryOptions
     * @return $this
     */
    public function setSecondaryOptions($secondaryOptions);

    /**
     * @param string $shippingFeeMerchantReference
     * @return $this
     */
    public function setShippingFeeMerchantReference($shippingFeeMerchantReference);

    /**
     * @param bool $supportsDynamicSecondaryOptions
     * @return $this
     */
    public function setSupportsDynamicSecondaryOptions($supportsDynamicSecondaryOptions);
}

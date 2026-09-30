<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\ShippingConfig;

use Qliro\QliroOne\Api\Data\QliroOrderShippingConfigUnifaunInterface;
use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodOptionInterface;

/**
 * QliroOne order shipping method option class
 */
class Unifaun implements QliroOrderShippingConfigUnifaunInterface
{
    /**
     * @var string
     */
    private $checkoutId;

    /**
     * @var array
     */
    private $tags;

    /**
     * @inheritdoc
     */
    public function getCheckoutId()
    {
        return $this->checkoutId;
    }

    /**
     * @inheritdoc
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * @inheritdoc
     */
    public function setCheckoutId($value)
    {
        $this->checkoutId = $value;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setTags($value)
    {
        $this->tags = $value;

        return $this;
    }
}

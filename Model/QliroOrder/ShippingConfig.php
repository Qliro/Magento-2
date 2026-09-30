<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface;

/**
 * QliroOne order shipping config class
 */
class ShippingConfig implements QliroOrderShippingConfigInterface
{
    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigUnifaunInterface
     */
    private $unifaun;

    /**
     * @inheritdoc
     */
    public function getUnifaun()
    {
        return $this->unifaun;
    }

    /**
     * @inheritdoc
     */
    public function setUnifaun($value)
    {
        $this->unifaun = $value;

        return $this;
    }
}

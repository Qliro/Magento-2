<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOne Order Shipping Config interface
 *
 * @api
 */
interface QliroOrderShippingConfigUnifaunInterface extends ContainerInterface
{
    /**
     * @return string
     */
    public function getCheckoutId();

    /**
     * @return array
     */
    public function getTags();

    /**
     * @param string $value
     * @return $this
     */
    public function setCheckoutId($value);

    /**
     * @param array $value
     * @return $this
     */
    public function setTags($value);
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOrderPaymentMethodInterface interface
 *
 * @api
 */
interface QliroOrderPaymentMethodInterface extends ContainerInterface
{
    /**
     * @return string
     */
    public function getPaymentMethodName();

    /**
     * @return string
     */
    public function getPaymentTypeCode();

    /**
     * @param string $value
     * @return $this
     */
    public function setPaymentMethodName($value);

    /**
     * @param string $value
     * @return $this
     */
    public function setPaymentTypeCode($value);
}

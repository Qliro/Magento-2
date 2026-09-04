<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Block\Info;

use Qliro\QliroOne\Model\Config;

class QliroOne extends AbstractInfo
{
    /**
     * QliroOne info template
     *
     * @var string
     */
    protected $_template = 'Qliro_QliroOne::info/qliroone.phtml';

    /**
     * @return string
     */
    public function toPdf()
    {
        $this->setTemplate('Qliro_QliroOne::info/pdf/qliroone.phtml');
        return $this->toHtml();
    }

    /**
     * @return string
     */
    public function getQliroOrderId()
    {
        return $this->getInfo()->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID);
    }

    /**
     * @return string
     */
    public function getQliroReference()
    {
        return $this->getInfo()->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_REFERENCE);
    }

    /**
     * @return string
     */
    public function getQliroMethod()
    {
        return $this->getInfo()->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_CODE);
    }

    /**
     * The method Qliro names for the order, falling back to the type code
     *
     * The name is the product, `QLIRO_INVOICE`, `QLIROPAYLATER_INVOICE30`, and it is what the
     * Ironman rollout renames. The type code is the instrument behind it, so every pay later
     * product collapses to `INVOICE` there, and a card order reads `MASTERCARD` or a bare number.
     *
     * @return string
     */
    public function getQliroMethodName()
    {
        $name = $this->getInfo()->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_NAME);

        return $name !== null && $name !== '' ? (string)$name : (string)$this->getQliroMethod();
    }
}

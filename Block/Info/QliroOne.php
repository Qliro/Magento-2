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
     * Readable name Qliro sent for the method, falling back to the raw code
     *
     * Qliro keeps adding method codes (the QLIROPAYLATER family), and the code alone is not
     * something a merchant can read.
     *
     * @return string
     */
    public function getQliroMethodName()
    {
        $name = $this->getInfo()->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_NAME);

        return $name !== null && $name !== '' ? (string)$name : (string)$this->getQliroMethod();
    }
}

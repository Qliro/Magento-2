<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Block\Info;

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
        return $this->getInfo()->getAdditionalInformation('qliro_order_id');
    }

    /**
     * @return string
     */
    public function getQliroReference()
    {
        return $this->getInfo()->getAdditionalInformation('qliro_reference');
    }

    /**
     * @return string
     */
    public function getQliroMethod()
    {
        return $this->getInfo()->getAdditionalInformation('qliro_payment_method_code');
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
        $name = $this->getInfo()->getAdditionalInformation('qliro_payment_method_name');

        return $name !== null && $name !== '' ? $name : (string)$this->getQliroMethod();
    }
}

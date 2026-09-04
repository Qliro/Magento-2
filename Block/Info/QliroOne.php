<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Block\Info;

use Magento\Framework\View\Element\Template\Context;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\PaymentMethodLabel;

class QliroOne extends AbstractInfo
{
    /**
     * QliroOne info template
     *
     * @var string
     */
    protected $_template = 'Qliro_QliroOne::info/qliroone.phtml';

    /**
     * @var \Qliro\QliroOne\Model\PaymentMethodLabel
     */
    private $paymentMethodLabel;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Qliro\QliroOne\Model\PaymentMethodLabel $paymentMethodLabel
     * @param array $data
     */
    public function __construct(
        Context $context,
        PaymentMethodLabel $paymentMethodLabel,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->paymentMethodLabel = $paymentMethodLabel;
    }

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

    /**
     * What the order view prints for the method: wording where the module has it, the name Qliro
     * sent where it does not
     *
     * @return string
     */
    public function getQliroMethodLabel()
    {
        return $this->paymentMethodLabel->getLabel($this->getQliroMethodName());
    }

    /**
     * Whether the raw name says anything the label does not, which is what decides if the order
     * view repeats it for support
     *
     * @return bool
     */
    public function hasQliroMethodLabel()
    {
        return $this->paymentMethodLabel->isKnown($this->getQliroMethodName());
    }
}

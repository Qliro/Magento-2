<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Info;

use Magento\Framework\Exception\LocalizedException;

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
    public function toPdf(): string
    {
        $this->setTemplate('Qliro_QliroOne::info/pdf/qliroone.phtml');
        return $this->toHtml();
    }

    /**
     * @return mixed
     * @throws LocalizedException
     */
    public function getQliroOrderId(): mixed
    {
        return $this->getInfo()->getAdditionalInformation('qliro_order_id');
    }

    /**
     * @return mixed
     * @throws LocalizedException
     */
    public function getQliroReference(): mixed
    {
        return $this->getInfo()->getAdditionalInformation('qliro_reference');
    }

    /**
     * @return mixed
     * @throws LocalizedException
     */
    public function getQliroMethod(): mixed
    {
        return $this->getInfo()->getAdditionalInformation('qliro_payment_method_code');
    }
}

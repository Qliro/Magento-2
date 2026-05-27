<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Adminhtml\Form\Field\Recurring\Renderer;

use Magento\Framework\View\Element\Html\Select;

/**
 * Time Unit renderer
 */
class TimeUnit extends Select
{
    const string OPTION_DAY = 'day';
    const string OPTION_WEEK = 'week';
    const string OPTION_MONTH = 'month';

    /**
     * @param string $value
     * @return static
     */
    public function setInputName(string $value): static
    {
        return $this->setName($value);
    }

    protected function _toHtml(): string
    {
        $this->addOption(self::OPTION_DAY, __('Day'));
        $this->addOption(self::OPTION_WEEK, __('Week'));
        $this->addOption(self::OPTION_MONTH, __('Month'));
        return parent::_toHtml();
    }
}

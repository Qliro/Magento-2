<?php declare(strict_types=1);

namespace Qliro\QliroOne\Block\Adminhtml\Form\Field\Recurring\Renderer;

/**
 * Time Unit renderer
 */
class TimeUnit extends \Magento\Framework\View\Element\Html\Select
{
    const OPTION_DAY = 'day';
    const OPTION_WEEK = 'week';
    const OPTION_MONTH = 'month';

    /**
     * @param string $value
     * @return $this
     */
    public function setInputName($value)
    {
        return $this->setName($value);
    }

    protected function _toHtml()
    {
        $this->addOption(self::OPTION_DAY, __('Day'));
        $this->addOption(self::OPTION_WEEK, __('Week'));
        $this->addOption(self::OPTION_MONTH, __('Month'));
        return parent::_toHtml();
    }
}

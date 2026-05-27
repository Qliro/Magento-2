<?php declare(strict_types=1);

namespace Qliro\QliroOne\Block\Adminhtml\Form\Field\Recurring\Renderer;

use Magento\Framework\View\Element\Html\Select;

/**
 * Frequency renderer
 */
class Frequency extends Select
{
    const string OPTION_EVERY = '1';
    const string OPTION_EVERY_OTHER = '2';
    const string OPTION_EVERY_THIRD = '3';

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
        $this->addOption(self::OPTION_EVERY, __('Every'));
        $this->addOption(self::OPTION_EVERY_OTHER, __('Every Other'));
        $this->addOption(self::OPTION_EVERY_THIRD, __('Every Third'));
        return parent::_toHtml();
    }
}

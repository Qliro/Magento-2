<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the Qliro logo at the top of the QliroOne admin config section.
 */
class Logo extends Field
{
    protected $_template = 'Qliro_QliroOne::system/config/logo.phtml';

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Bypass the standard field <tr> wrapper entirely so the logo sits flush
     * under the group heading spanning the full config table width.
     *
     * @inheritDoc
     */
    public function render(AbstractElement $element): string
    {
        return '<tr><td colspan="4" style="padding: 16px 24px 8px;">'
            . $this->_toHtml()
            . '</td></tr>';
    }
}

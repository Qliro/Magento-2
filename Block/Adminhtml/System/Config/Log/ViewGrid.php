<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Adminhtml\System\Config\Log;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;

/**
 * Renders a button in the admin config that links to the QliroOne log grid.
 */
class ViewGrid extends Field
{
    protected $_template = 'Qliro_QliroOne::system/config/log/view_grid.phtml';

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * @throws LocalizedException
     */
    public function getButtonHtml(): string
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class);
        $button->setData([
            'id'      => 'view_log_grid_button',
            'label'   => __('View Log Grid'),
            'onclick' => 'window.location.href=\'' . $this->getLogGridUrl() . '\'',
        ]);

        return $button->toHtml();
    }

    /**
     * @return string
     */
    public function getLogGridUrl(): string
    {
        return $this->getUrl('qliroone/log/index');
    }
}

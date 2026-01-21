<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Adminhtml\System\Config\Download;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;

/**
 * Class Logs
 */
class Logs extends Field
{
    protected $_template = 'Qliro_QliroOne::system/config/download/logs.phtml';

    /**
     * Retrieve element HTML markup
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Generate button HTML
     *
     * @return string
     * @throws LocalizedException
     */
    public function getButtonHtml(): string
    {
        $button = $this->getLayout()->createBlock(Button::class);
        
        /** @var Button $button */
        $button->setData(
            [
                'id' => 'download_logs_button',
                'label' => __('Download'),
                'onclick' => 'window.open(\'' . $this->getLogDownloadUrl() . '\')',
            ]
        );
        return $button->toHtml();
    }

    /**
     * Get download URL
     *
     * @return string
     */
    public function getLogDownloadUrl(): string
    {
        return $this->getUrl('qliroone/log/download', ['type' => 'logs']);
    }
}

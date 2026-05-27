<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Adminhtml\Log;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Qliro\QliroOne\Model\LogRecord;

/**
 * Block for the QliroOne log record view page.
 *
 * @method string getTemplate()
 */
class View extends Template
{
    /**
     * Class constructor
     *
     * @param Context             $context
     * @param Registry            $registry
     * @param array               $data
     */
    public function __construct(
        Context                   $context,
        private readonly Registry $registry,
        array                     $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Return the log record registered by the controller.
     */
    public function getLogRecord(): LogRecord
    {
        return $this->registry->registry('qliroone_log_record');
    }

    /**
     * URL for the "Back" button.
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('qliroone/log/index');
    }
}

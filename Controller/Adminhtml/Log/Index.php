<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index implements \Magento\Framework\App\ActionInterface
{
    public const ADMIN_RESOURCE = 'Qliro_QliroOne::log';

    /**
     * Class constructor
     *
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        private readonly PageFactory $resultPageFactory
    ) {
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Qliro_QliroOne::log');
        $resultPage->getConfig()->getTitle()->prepend(__('Qliro One Logs'));

        return $resultPage;
    }
}

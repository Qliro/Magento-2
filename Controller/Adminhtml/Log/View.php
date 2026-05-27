<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Qliro\QliroOne\Model\LogRecord;
use Qliro\QliroOne\Model\LogRecordFactory;
use Qliro\QliroOne\Model\ResourceModel\LogRecord as LogRecordResource;

/**
 * Admin controller for viewing a single QliroOne log record.
 */
class View extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'Qliro_QliroOne::log_view';

    /**
     * Class constructor
     *
     * @param Context                      $context
     * @param LogRecordFactory             $logRecordFactory
     * @param LogRecordResource            $logRecordResource
     * @param Registry                     $registry
     */
    public function __construct(
        Context                            $context,
        private readonly LogRecordFactory  $logRecordFactory,
        private readonly LogRecordResource $logRecordResource,
        private readonly Registry          $registry
    ) {
        parent::__construct($context);
    }

    /**
     * Load the log record and render the view page, or redirect back on error.
     */
    public function execute(): Page|Redirect
    {
        $id = (int) $this->getRequest()->getParam('id');

        /** @var LogRecord $logRecord */
        $logRecord = $this->logRecordFactory->create();
        $this->logRecordResource->load($logRecord, $id);

        if (!$logRecord->getId()) {
            $this->messageManager->addErrorMessage(__('Log record #%1 does not exist.', $id));
            /** @var Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $redirect->setPath('*/*/index');
        }

        $this->registry->register('qliroone_log_record', $logRecord);

        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Qliro_QliroOne::log_view');
        $resultPage->getConfig()->getTitle()->prepend(__('QliroOne Logs'));
        $resultPage->getConfig()->getTitle()->prepend(__('Log #%1', $id));

        return $resultPage;
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Qliro\QliroOne\Model\ResourceModel\LogRecord as LogRecordResource;
use Qliro\QliroOne\Model\ResourceModel\LogRecord\CollectionFactory;

/**
 * Class MassDelete
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'Qliro_QliroOne::log_delete';

    /**
     * Class constructor
     *
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param LogRecordResource $logRecordResource
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly LogRecordResource $logRecordResource,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $selectedIds = $collection->getAllIds();
        $totalSelected = count($selectedIds);

        $cutoffDate = (new \DateTime('-1 month'))->format('Y-m-d H:i:s');
        $connection = $this->logRecordResource->getConnection();
        $tableName = $this->logRecordResource->getMainTable();

        $deleted = $connection->delete($tableName, [
            'id IN (?)' => $selectedIds,
            'date < ?' => $cutoffDate,
        ]);

        $skipped = $totalSelected - $deleted;

        if ($deleted > 0) {
            $this->messageManager->addSuccessMessage(__('Deleted %1 log record(s).', $deleted));
        }
        if ($skipped > 0) {
            $this->messageManager->addNoticeMessage(
                __('%1 record(s) were skipped because they are less than 1 month old.', $skipped)
            );
        }
        if ($totalSelected === 0) {
            $this->messageManager->addErrorMessage(__('No records were selected.'));
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('*/*/index');
    }
}

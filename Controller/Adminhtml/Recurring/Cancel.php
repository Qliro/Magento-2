<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Adminhtml\Recurring;

use Magento\Framework\App\Action\HttpPostActionInterface as HttpPostActionInterface;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Setup\Module\Di\Definition\Collection;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

class Cancel extends \Magento\Sales\Controller\Adminhtml\Order\AbstractMassAction implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Sales::sales_order';

    /**
     * @var string
     */
    protected $redirectUrl = 'sales/order/index';
    
    /**
     * @var \Qliro\QliroOne\Api\RecurringInfoRepositoryInterface
     */
    private $recurringInfoRepo;

    /**
     * @var \Qliro\QliroOne\Service\RecurringPayments\Data
     */
    private $recurringDataService;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param RecurringInfoRepositoryInterface $recurringInfoRepo
     * @param RecurringDataService $recurringDataService
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        RecurringInfoRepositoryInterface $recurringInfoRepo,
        RecurringDataService $recurringDataService,
    ) {
        parent::__construct($context, $filter);
        $this->collectionFactory = $collectionFactory;
        $this->recurringInfoRepo = $recurringInfoRepo;
        $this->recurringDataService = $recurringDataService;
    }

    /**
     * Cancel recurring for selected orders
     *
     * @param AbstractCollection $collection
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    protected function massAction(AbstractCollection $collection)
    {
        foreach ($collection->getItems() as $order) {
            try {
        
                $recurringInfo = $this->recurringInfoRepo->getByOriginalOrderId((int)$order->getEntityId());
                if (!$recurringInfo->getId()) {
                    $this->messageManager->addNoticeMessage(
                        __('Order #%1 is not a recurring order.', $order->getIncrementId())
                    );
                    continue;
                }
                if($recurringInfo->getNextOrderDate() === null) {
                    $this->messageManager->addNoticeMessage(
                        __('The recurring order #%1 was canceled on #%2 .', $order->getIncrementId(), $recurringInfo->getCanceledDate())
                    );
                    continue;
                }
                $this->recurringDataService->cancel($recurringInfo);
                $this->recurringInfoRepo->save($recurringInfo);
                $this->messageManager->addSuccessMessage(
                    __('The recurring subscription has been successfully canceled for order #%1 .', $order->getIncrementId())
                );
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath($this->redirectUrl);
        return $resultRedirect;
    }
}
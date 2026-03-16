<?php declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Recurring;

use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Controller\AbstractController\OrderViewAuthorizationInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;

/**
 * Cancel recurring payment Controller for Customers
 */
class Cancel implements HttpPostActionInterface
{
    /**
     * Class constructor
     *
     * @param Http $request
     * @param OrderViewAuthorizationInterface $orderAuthorization
     * @param MessageManager $messageManager
     * @param RecurringDataService $recurringDataService
     * @param OrderRepositoryInterface $orderRepo
     * @param RecurringInfoRepositoryInterface $recurringInfoRepo
     * @param Config $config
     * @param ForwardFactory $forwardFactory
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        private readonly Http $request,
        private readonly OrderViewAuthorizationInterface $orderAuthorization,
        private readonly MessageManager $messageManager,
        private readonly RecurringDataService $recurringDataService,
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly RecurringInfoRepositoryInterface $recurringInfoRepo,
        private readonly Config $config,
        private readonly ForwardFactory $resultForwardFactory,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        if (!$this->config->isActive() || !$this->config->isUseRecurring()) {
            return $this->resultForwardFactory->create()->forward('noroute');
        }

        $orderId = (int)$this->request->getParam('order_id');
        $resultRedirect = $this->redirectFactory->create();

        try {
            $order = $this->orderRepo->get($orderId);
            $recurringInfo = $this->recurringInfoRepo->getByOriginalOrderId($orderId);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Recurring Info not found'));
            return $resultRedirect->setPath('sales/order/history');
        }

        if (!$this->orderAuthorization->canView($order)) {
            $this->messageManager->addErrorMessage(__('You don\'t have permission do this'));
            return $resultRedirect->setPath('sales/order/history');
        }

        try {
            $this->recurringDataService->cancel($recurringInfo);
            $this->recurringInfoRepo->save($recurringInfo);
        } catch (\Exception $e) {
            $message =
                'An error occurred when trying to cancel your subscription.'
                . ' Please contact customer service for assistance.';

                $this->messageManager->addErrorMessage(__($message));
                return $this->redirectBackToHistoryView();
        }

        $this->messageManager->addSuccessMessage(__('Your subscription is now cancelled'));
        return $this->redirectBackToHistoryView();
    }

    /**
     * @return Redirect
     */
    private function redirectBackToHistoryView(): Redirect
    {
        return $this->redirectFactory->create()->setPath(
            'checkout/qliro_recurring/history',
            ['order_id' => $this->request->getParam('order_id')]
        );
    }
}

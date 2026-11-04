<?php declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Recurring;

use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Controller\AbstractController\OrderViewAuthorizationInterface;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Service\RecurringPayments\RemoveSavedCard;

/**
 * Removes the saved card of a customer's cancelled subscription
 */
class RemoveCard implements HttpPostActionInterface
{
    private Http $request;

    private OrderViewAuthorizationInterface $orderAuthorization;

    private MessageManager $messageManager;

    private OrderRepositoryInterface $orderRepo;

    private RecurringInfoRepositoryInterface $recurringInfoRepo;

    private RemoveSavedCard $removeSavedCard;

    private Config $config;

    private ForwardFactory $resultForwardFactory;

    private RedirectFactory $redirectFactory;

    private LogManager $logManager;

    public function __construct(
        Http $request,
        OrderViewAuthorizationInterface $orderAuthorization,
        MessageManager $messageManager,
        OrderRepositoryInterface $orderRepo,
        RecurringInfoRepositoryInterface $recurringInfoRepo,
        RemoveSavedCard $removeSavedCard,
        Config $config,
        ForwardFactory $forwardFactory,
        RedirectFactory $redirectFactory,
        LogManager $logManager
    ) {
        $this->request = $request;
        $this->orderAuthorization = $orderAuthorization;
        $this->messageManager = $messageManager;
        $this->orderRepo = $orderRepo;
        $this->recurringInfoRepo = $recurringInfoRepo;
        $this->removeSavedCard = $removeSavedCard;
        $this->config = $config;
        $this->resultForwardFactory = $forwardFactory;
        $this->redirectFactory = $redirectFactory;
        $this->logManager = $logManager;
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

        try {
            $order = $this->orderRepo->get($orderId);
            $recurringInfo = $this->recurringInfoRepo->getByOriginalOrderId($orderId);
        } catch (\Exception $e) {
            $order = null;
            $recurringInfo = null;
        }

        if (!$order || !$recurringInfo || !$recurringInfo->getId() || !$this->orderAuthorization->canView($order)) {
            $this->messageManager->addErrorMessage(__('Subscription not found'));
            return $this->redirectFactory->create()->setPath('checkout/qliro_recurring/history');
        }

        try {
            $result = $this->removeSavedCard->execute($recurringInfo);
        } catch (\Exception $e) {
            $this->logManager->critical($e, ['extra' => ['order_id' => $orderId]]);
            $this->messageManager->addErrorMessage(
                __('Your saved card could not be removed. Please try again or contact customer service.')
            );
            return $this->redirectBackToHistoryView();
        }

        switch ($result) {
            case RemoveSavedCard::REMOVED:
                $this->messageManager->addSuccessMessage(__('Your saved card has been removed'));
                break;
            case RemoveSavedCard::NOT_CANCELED:
                $this->messageManager->addErrorMessage(__('Cancel the subscription before removing its card'));
                break;
            default:
                $this->messageManager->addErrorMessage(__('This subscription has no saved card'));
        }

        return $this->redirectBackToHistoryView();
    }

    /**
     * @return Redirect
     */
    private function redirectBackToHistoryView(): Redirect
    {
        return $this->redirectFactory->create()->setPath('checkout/qliro_recurring/history');
    }
}

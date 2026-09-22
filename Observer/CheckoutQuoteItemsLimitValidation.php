<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Qliro\QliroOne\Model\Quote\ItemsLimitValidator;

/**
 * Class responsible for validating the quote items limit at checkout
 */
class CheckoutQuoteItemsLimitValidation implements ObserverInterface
{
    /**
     * Class constructor
     *
     * @param CheckoutSession                $checkoutSession
     * @param ItemsLimitValidator            $itemsLimitValidator
     * @param MessageManager                 $messageManager
     * @param RedirectInterface              $redirect
     * @param ActionFlag                     $actionFlag
     */
    public function __construct(
        private readonly CheckoutSession     $checkoutSession,
        private readonly ItemsLimitValidator $itemsLimitValidator,
        private readonly MessageManager      $messageManager,
        private readonly RedirectInterface   $redirect,
        private readonly ActionFlag          $actionFlag
    ) {
    }

    /**
     * Executes the observer logic to validate the quote items limit at checkout
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $controller = $observer->getEvent()->getControllerAction();

        $quote = $this->checkoutSession->getQuote();
        if (!$quote || !$quote->getId()) {
            return;
        }

        try {
            $this->itemsLimitValidator->validateQuoteItemsLimit($quote);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
            $this->redirect->redirect($controller->getResponse(), 'checkout/cart');
        }
    }
}

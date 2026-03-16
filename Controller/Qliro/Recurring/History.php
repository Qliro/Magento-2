<?php
namespace Qliro\QliroOne\Controller\Qliro\Recurring;

use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;
use Magento\Sales\Controller\OrderInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Qliro\QliroOne\Model\Config;

/**
 * Subscription History / List
 */
class History implements OrderInterface, AccountInterface, HttpGetActionInterface
{
    /**
     * Class constructor
     *
     * @param PageFactory $resultPageFactory
     * @param ForwardFactory $forwardFactory
     * @param RedirectInterface $redirect
     * @param Config $config
     */
    public function __construct(
        private readonly PageFactory $resultPageFactory,
        private readonly ForwardFactory $resultForwardFactory,
        private readonly RedirectInterface $redirect,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        if (!$this->config->isActive() || !$this->config->isUseRecurring()) {
            return $this->resultForwardFactory->create()->forward('noroute');
        }

        /** @var \Magento\Framework\View\Result\Page $resultPage */
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set(__('My Subscriptions through Qliro'));

        $block = $resultPage->getLayout()->getBlock('customer.account.link.back');
        if ($block) {
            /** @var \Magento\Customer\Block\Account\Dashboard $block */
            $block->setRefererUrl($this->redirect->getRefererUrl());
        }
        return $resultPage;
    }
}

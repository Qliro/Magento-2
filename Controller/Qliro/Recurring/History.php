<?php

declare(strict_types=1);

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
     * @var PageFactory
     */
    private PageFactory $resultPageFactory;

    /**
     * @var ForwardFactory
     */
    private ForwardFactory $resultForwardFactory;

    /**
     * @var RedirectInterface
     */
    private RedirectInterface $redirect;

    /**
     * @var Config
     */
    private Config $config;

    public function __construct(
        PageFactory $resultPageFactory,
        ForwardFactory $forwardFactory,
        RedirectInterface $redirect,
        Config $config
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->resultForwardFactory = $forwardFactory;
        $this->redirect = $redirect;
        $this->config = $config;
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

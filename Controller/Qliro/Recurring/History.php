<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Recurring;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Sales\Controller\OrderInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Backend\Model\View\Result\ForwardFactory;
use Qliro\QliroOne\Model\Config;

/**
 * Subscription History / List
 *
 * Not an AccountInterface page: core's login plugin answers a guest with null, which only the
 * deprecated Action base turns into a response, so off it the guest got a 500.
 */
class History implements OrderInterface, HttpGetActionInterface
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

    private CustomerSession $customerSession;

    private HttpResponse $response;

    public function __construct(
        PageFactory $resultPageFactory,
        ForwardFactory $forwardFactory,
        RedirectInterface $redirect,
        Config $config,
        CustomerSession $customerSession,
        HttpResponse $response
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->resultForwardFactory = $forwardFactory;
        $this->redirect = $redirect;
        $this->config = $config;
        $this->customerSession = $customerSession;
        $this->response = $response;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        if (!$this->config->isActive() || !$this->config->isUseRecurring()) {
            return $this->resultForwardFactory->create()->forward('noroute');
        }

        // Sets the login redirect on the response and remembers this page to come back to
        if (!$this->customerSession->authenticate()) {
            return $this->response;
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

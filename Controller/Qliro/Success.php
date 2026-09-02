<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Registry;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\LayoutFactory as ViewLayoutFactory;
use Magento\Framework\View\Result\LayoutFactory as ResultLayoutFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Qliro\QliroOne\Model\Quote\Agent;
use Qliro\QliroOne\Model\Success\Session as SuccessSession;

/**
 * Order success action
 */
class Success extends \Magento\Checkout\Controller\Onepage
{
    /**
     * @var \Qliro\QliroOne\Model\Quote\Agent
     */
    private $quoteAgent;
    /**
     * @var SuccessSession
     */
    private $successSession;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param \Magento\Customer\Api\AccountManagementInterface $accountManagement
     * @param \Magento\Framework\Registry $coreRegistry
     * @param \Magento\Framework\Translate\InlineInterface $translateInline
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\View\LayoutFactory $layoutFactory
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     * @param \Magento\Framework\View\Result\LayoutFactory $resultLayoutFactory
     * @param \Magento\Framework\Controller\Result\RawFactory $resultRawFactory
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Qliro\QliroOne\Model\Quote\Agent $quoteAgent
     * @param SuccessSession $successSession
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        CustomerRepositoryInterface $customerRepository,
        AccountManagementInterface $accountManagement,
        Registry $coreRegistry,
        InlineInterface $translateInline,
        Validator $formKeyValidator,
        ScopeConfigInterface $scopeConfig,
        ViewLayoutFactory $layoutFactory,
        CartRepositoryInterface $quoteRepository,
        PageFactory $resultPageFactory,
        ResultLayoutFactory $resultLayoutFactory,
        RawFactory $resultRawFactory,
        JsonFactory $resultJsonFactory,
        Agent $quoteAgent,
        SuccessSession $successSession
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $customerRepository,
            $accountManagement,
            $coreRegistry,
            $translateInline,
            $formKeyValidator,
            $scopeConfig,
            $layoutFactory,
            $quoteRepository,
            $resultPageFactory,
            $resultLayoutFactory,
            $resultRawFactory,
            $resultJsonFactory
        );

        $this->quoteAgent = $quoteAgent;
        $this->successSession = $successSession;
    }

    /**
     * Dispatch a QliroOne checkout success page or redirect to the cart
     *
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        if (!$this->successSession->getSuccessIncrementId()) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        if (!$this->successSession->hasSuccessDisplayed()) {
            $this->quoteAgent->clear();
        }

        $resultPage = $this->resultPageFactory->create();

        if (!$this->successSession->hasSuccessDisplayed()) {
            /*
             * The order goes out with the ids because core's own success page sends both, and a
             * tracking extension that reads the order instead of loading it by id gets null
             * without it.
             */
            $this->_eventManager->dispatch(
                'checkout_onepage_controller_success_action',
                [
                    'order_ids' => [$this->successSession->getSuccessOrderId()],
                    'order' => $this->getOnepage()->getCheckout()->getLastRealOrder(),
                ]
            );
            $this->successSession->setSuccessDisplayed();
        }

        return $resultPage;
    }
}

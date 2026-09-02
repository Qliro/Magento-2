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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\LayoutFactory as ViewLayoutFactory;
use Magento\Framework\View\Result\LayoutFactory as ResultLayoutFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
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
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

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
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
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
        SuccessSession $successSession,
        OrderRepositoryInterface $orderRepository
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
        $this->orderRepository = $orderRepository;
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
             * without it. It comes from the id this page owns rather than from the session, so
             * the two can never describe different orders.
             */
            $this->_eventManager->dispatch(
                'checkout_onepage_controller_success_action',
                [
                    'order_ids' => [$this->successSession->getSuccessOrderId()],
                    'order' => $this->getOrder(),
                ]
            );
            $this->successSession->setSuccessDisplayed();
        }

        return $resultPage;
    }

    /**
     * Get the order the success page was reached with, or null if it cannot be loaded
     *
     * Null rather than an empty order on purpose: a listener that reads the order would report a
     * purchase of zero from a placeholder, while null is the absence every listener already tests
     * for. Core hands out `getLastRealOrder()`, which is an empty order when the session lost its
     * id, and that is the shape being avoided here.
     *
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    private function getOrder()
    {
        try {
            return $this->orderRepository->get($this->successSession->getSuccessOrderId());
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }
}

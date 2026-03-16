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
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Registry;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\LayoutFactory as ViewLayoutFactory;
use Magento\Framework\View\Result\LayoutFactory as ResultLayoutFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Quote\Agent;
use Qliro\QliroOne\Model\Success\Session as SuccessSession;

/**
 * Order success action.
 *
 * The Magento order is now created during HtmlSnippet::get() (when the checkout page loads)
 * in STATE_PENDING_PAYMENT. By the time the customer is redirected here the order is
 * guaranteed to exist — the link record carries order_id from that point forward.
 *
 * The CheckoutStatus callback updates the order to its terminal state asynchronously, but
 * the success page only needs the order to exist, not to be in its final state.
 */
class Success extends \Magento\Checkout\Controller\Onepage
{
    /**
     * Class constructor
     *
     * @param Context $context
     * @param Session $customerSession
     * @param CheckoutSession $checkoutSession
     * @param CustomerRepositoryInterface $customerRepository
     * @param AccountManagementInterface $accountManagement
     * @param Registry $coreRegistry
     * @param InlineInterface $translateInline
     * @param Validator $formKeyValidator
     * @param ScopeConfigInterface $scopeConfig
     * @param ViewLayoutFactory $layoutFactory
     * @param CartRepositoryInterface $quoteRepository
     * @param PageFactory $resultPageFactory
     * @param ResultLayoutFactory $resultLayoutFactory
     * @param RawFactory $resultRawFactory
     * @param JsonFactory $resultJsonFactory
     * @param Agent $quoteAgent
     * @param SuccessSession $successSession
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        private readonly CheckoutSession $checkoutSession,
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
        private readonly Agent $quoteAgent,
        private readonly SuccessSession $successSession,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger
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
    }

    /**
     * Dispatch a QliroOne checkout success page or redirect to the cart.
     *
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        if (!$this->successSession->getSuccessIncrementId()) {
            if (!$this->populateSuccessSessionFromCookie()) {
                return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }
        }

        if (!$this->successSession->hasSuccessDisplayed()) {
            $this->quoteAgent->clear();
            $this->deactivateQuote();
        }

        $resultPage = $this->resultPageFactory->create();

        if (!$this->successSession->hasSuccessDisplayed()) {
            $this->_eventManager->dispatch(
                'checkout_onepage_controller_success_action',
                ['order_ids' => [$this->successSession->getSuccessOrderId()]]
            );
            $this->successSession->setSuccessDisplayed();
        }

        return $resultPage;
    }

    /**
     * Populate the success session from the QOMR cookie → link → order chain.
     *
     * The order is created in STATE_PENDING_PAYMENT during HtmlSnippet::get(), so
     * link->order_id is set before the customer ever reaches this page. A single
     * database read is sufficient — no polling is needed.
     *
     * @return bool
     */
    private function populateSuccessSessionFromCookie(): bool
    {
        $merchantReference = $this->quoteAgent->getMerchantReferenceFromCookie();

        if (empty($merchantReference)) {
            $this->logger->warning('QliroOne Success: QOMR cookie missing, cannot recover order.');
            return false;
        }

        // The CheckoutStatus server-to-server callback and the browser redirect to this page
        // are fired by Qliro simultaneously. When the order was not pre-placed during checkout
        // load (late-placement fallback), the callback may still be creating the order while
        // the browser already arrives here. Retry for up to ~6 seconds so the callback has
        // time to finish placing the order before we give up.
        $maxAttempts = 4;
        $retryDelayUs = 2000000; // 2 seconds

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $link = $this->linkRepository->getByReference(
                    $merchantReference,
                    false // include inactive links in case status update deactivated it
                );

                $orderId = $link->getOrderId();

                if (!empty($orderId)) {
                    $order = $this->orderRepository->get($orderId);
                    $this->successSession->save(null, $order);
                    return true;
                }

                $this->logger->info(sprintf(
                    'QliroOne Success: order not yet placed for reference %s (attempt %d/%d), waiting…',
                    $merchantReference, $attempt, $maxAttempts
                ));

            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'QliroOne Success: failed to load order for reference %s: %s',
                    $merchantReference,
                    $e->getMessage()
                ));
                return false;
            }

            if ($attempt < $maxAttempts) {
                usleep($retryDelayUs);
            }
        }

        $this->logger->error(sprintf(
            'QliroOne Success: order still not found after %d attempts for reference %s.',
            $maxAttempts,
            $merchantReference
        ));
        return false;
    }
    /**
     * Deactivate the quote associated with the completed order so the cart is
     * empty for the customer after checkout.
     *
     * Normally this would be handled by the async CheckoutStatus callback, but
     * on local environments (where Qliro cannot reach localhost) that callback
     * never fires. We deactivate eagerly here on first Success page load so the
     * cart is always cleared regardless of whether the callback arrives.
     *
     * The quote is loaded via the link record populated in populateSuccessSessionFromCookie().
     * If no link is found (redirect without cookie) we fall back to the checkout
     * session's current quote.
     */
    private function deactivateQuote(): void
    {
        try {
            $merchantReference = $this->quoteAgent->getMerchantReferenceFromCookie();
            if ($merchantReference) {
                $link = $this->linkRepository->getByReference($merchantReference, false);
                $quote = $this->quoteRepository->get($link->getQuoteId());
            } else {
                $quote = $this->checkoutSession->getQuote();
            }

            if ($quote && $quote->getId() && $quote->getIsActive()) {
                $quote->setIsActive(false);
                $this->quoteRepository->save($quote);
            }
        } catch (\Exception $e) {
            // Non-fatal — log and continue. Cart can be cleared manually.
            $this->logger->warning(
                'QliroOne Success: could not deactivate quote: ' . $e->getMessage()
            );
        }

        // Replace checkout session quote so the mini-cart shows empty immediately
        // without requiring a page reload or cache invalidation.
        try {
            $this->checkoutSession->replaceQuote(
                $this->checkoutSession->getQuote()->setIsActive(false)
            );
        } catch (\Exception $e) {
            // Swallow — visual glitch only, order is not affected.
        }
    }
}

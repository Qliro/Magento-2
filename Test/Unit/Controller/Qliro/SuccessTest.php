<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller\Qliro;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\LayoutFactory as ViewLayoutFactory;
use Magento\Framework\View\Result\LayoutFactory as ResultLayoutFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Controller\Qliro\Success;
use Qliro\QliroOne\Model\Quote\Agent;
use Qliro\QliroOne\Model\Success\Session as SuccessSession;

/**
 * @see \Qliro\QliroOne\Controller\Qliro\Success::execute
 *
 * PLIN-390: this page is where purchase tracking happens, and the event it dispatches is the
 * integration point partners build on. It has to carry the order the way core's success page
 * does, and it has to stay fire-once so a reload cannot count a second purchase.
 */
class SuccessTest extends TestCase
{
    private const ORDER_ID = 42;

    private ManagerInterface&MockObject $eventManager;
    private Agent&MockObject $quoteAgent;
    private Order&MockObject $order;

    protected function setUp(): void
    {
        $this->eventManager = $this->createMock(ManagerInterface::class);
        $this->quoteAgent = $this->createMock(Agent::class);
        $this->order = $this->createMock(Order::class);
    }

    /**
     * Core dispatches the ids and the order, and an extension reading `getEvent()->getOrder()`
     * gets null from a payload that carries only the ids.
     */
    public function testDispatchesTheOrderAlongWithTheOrderIds(): void
    {
        $controller = $this->controller($this->successSession(false));

        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with(
                'checkout_onepage_controller_success_action',
                ['order_ids' => [self::ORDER_ID], 'order' => $this->order]
            );

        self::assertInstanceOf(Page::class, $controller->execute());
    }

    /**
     * A reload of the success page must not count a second purchase.
     */
    public function testDoesNotDispatchAgainOnceTheSuccessPageWasDisplayed(): void
    {
        $controller = $this->controller($this->successSession(true));

        $this->eventManager->expects(self::never())->method('dispatch');
        $this->quoteAgent->expects(self::never())->method('clear');

        self::assertInstanceOf(Page::class, $controller->execute());
    }

    /**
     * No order in the session is no purchase, and the buyer belongs back in the cart.
     */
    public function testRedirectsToTheCartWithoutAnOrder(): void
    {
        $successSession = $this->createMock(SuccessSession::class);
        $successSession->method('getSuccessIncrementId')->willReturn(null);

        $this->eventManager->expects(self::never())->method('dispatch');

        self::assertInstanceOf(Redirect::class, $this->controller($successSession)->execute());
    }

    /**
     * @param bool $displayed Whether the success page was already shown for this order
     * @return \Qliro\QliroOne\Model\Success\Session&\PHPUnit\Framework\MockObject\MockObject
     */
    private function successSession(bool $displayed): SuccessSession&MockObject
    {
        $successSession = $this->createMock(SuccessSession::class);
        $successSession->method('getSuccessIncrementId')->willReturn('1001211998');
        $successSession->method('getSuccessOrderId')->willReturn(self::ORDER_ID);
        $successSession->method('hasSuccessDisplayed')->willReturn($displayed);

        return $successSession;
    }

    /**
     * The controller with core's own dependency list mocked out. `getOnepage()` resolves through
     * the object manager, which is how the checkout session, and with it the order core puts in
     * the event, is reached.
     *
     * @param \Qliro\QliroOne\Model\Success\Session $successSession
     * @return \Qliro\QliroOne\Controller\Qliro\Success
     */
    private function controller(SuccessSession $successSession): Success
    {
        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getLastRealOrder')->willReturn($this->order);
        $onepage = $this->createMock(Onepage::class);
        $onepage->method('getCheckout')->willReturn($checkoutSession);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->with(Onepage::class)->willReturn($onepage);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createMock(Context::class);
        $context->method('getEventManager')->willReturn($this->eventManager);
        $context->method('getObjectManager')->willReturn($objectManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->method('create')->willReturn($this->createMock(Page::class));

        return new Success(
            $context,
            $this->createMock(CustomerSession::class),
            $this->createMock(CustomerRepositoryInterface::class),
            $this->createMock(AccountManagementInterface::class),
            $this->createMock(Registry::class),
            $this->createMock(InlineInterface::class),
            $this->createMock(Validator::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(ViewLayoutFactory::class),
            $this->createMock(CartRepositoryInterface::class),
            $pageFactory,
            $this->createMock(ResultLayoutFactory::class),
            $this->createMock(RawFactory::class),
            $this->createMock(JsonFactory::class),
            $this->quoteAgent,
            $successSession
        );
    }
}

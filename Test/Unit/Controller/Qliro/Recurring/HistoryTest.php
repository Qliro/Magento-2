<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller\Qliro\Recurring;

use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\View\Layout;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Controller\Qliro\Recurring\History;
use Qliro\QliroOne\Model\Config;

/**
 * @see \Qliro\QliroOne\Controller\Qliro\Recurring\History::execute
 */
class HistoryTest extends TestCase
{
    private CustomerSession&MockObject $customerSession;
    private PageFactory&MockObject $pageFactory;
    private ForwardFactory&MockObject $forwardFactory;
    private HttpResponse&MockObject $response;

    protected function setUp(): void
    {
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->pageFactory = $this->createMock(PageFactory::class);
        $this->forwardFactory = $this->createMock(ForwardFactory::class);
        $this->response = $this->createMock(HttpResponse::class);
    }

    /**
     * Http::launch throws "Invalid return type" on null, so the guest needs the response
     * authenticate() put the login redirect on
     */
    public function testAGuestGetsTheLoginRedirect(): void
    {
        $this->customerSession->expects(self::once())->method('authenticate')->willReturn(false);
        $this->pageFactory->expects(self::never())->method('create');

        self::assertSame($this->response, $this->controller()->execute());
    }

    public function testALoggedInCustomerGetsTheSubscriptionsPage(): void
    {
        $this->customerSession->method('authenticate')->willReturn(true);
        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('set');
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $layout = $this->createMock(Layout::class);
        $layout->method('getBlock')->willReturn(false);
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->method('getLayout')->willReturn($layout);
        $this->pageFactory->method('create')->willReturn($page);

        self::assertSame($page, $this->controller()->execute());
    }

    public function testThePageIsNotFoundWhileRecurringIsOff(): void
    {
        $forward = $this->createMock(Forward::class);
        $forward->expects(self::once())->method('forward')->with('noroute')->willReturnSelf();
        $this->forwardFactory->method('create')->willReturn($forward);
        $this->customerSession->expects(self::never())->method('authenticate');

        self::assertSame($forward, $this->controller(false)->execute());
    }

    private function controller(bool $recurring = true): History
    {
        $config = $this->createMock(Config::class);
        $config->method('isActive')->willReturn(true);
        $config->method('isUseRecurring')->willReturn($recurring);

        return new History(
            $this->pageFactory,
            $this->forwardFactory,
            $this->createMock(RedirectInterface::class),
            $config,
            $this->customerSession,
            $this->response
        );
    }
}

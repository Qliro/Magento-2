<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller\Qliro\Recurring;

use Magento\Backend\Model\View\Result\ForwardFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Controller\AbstractController\OrderViewAuthorizationInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;
use Qliro\QliroOne\Controller\Qliro\Recurring\RemoveCard;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\RecurringInfo;
use Qliro\QliroOne\Model\ResourceModel\RecurringInfo as RecurringInfoResource;
use Qliro\QliroOne\Service\RecurringPayments\RemoveSavedCard;

/**
 * @see \Qliro\QliroOne\Controller\Qliro\Recurring\RemoveCard::execute
 */
class RemoveCardTest extends TestCase
{
    private OrderViewAuthorizationInterface&MockObject $authorization;
    private MessageManager&MockObject $messages;
    private RecurringInfoRepositoryInterface&MockObject $recurringInfoRepo;
    private RemoveSavedCard&MockObject $removeSavedCard;
    private Redirect&MockObject $redirect;
    private RecurringInfo $info;

    protected function setUp(): void
    {
        $this->authorization = $this->createMock(OrderViewAuthorizationInterface::class);
        $this->messages = $this->createMock(MessageManager::class);
        $this->recurringInfoRepo = $this->createMock(RecurringInfoRepositoryInterface::class);
        $this->removeSavedCard = $this->createMock(RemoveSavedCard::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $this->info = $this->recurringInfo();
        $this->info->setData(['entity_id' => 5, 'original_order_id' => 10]);
        $this->recurringInfoRepo->method('getByOriginalOrderId')->with(10)->willReturn($this->info);
    }

    public function testAnotherCustomersSubscriptionIsNotTouched(): void
    {
        $this->authorization->method('canView')->willReturn(false);
        $this->removeSavedCard->expects(self::never())->method('execute');
        $this->messages->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->redirect, $this->controller()->execute());
    }

    public function testAnUnknownSubscriptionIsNotFound(): void
    {
        $this->info->setData([]);
        $this->authorization->method('canView')->willReturn(true);
        $this->removeSavedCard->expects(self::never())->method('execute');
        $this->messages->expects(self::once())->method('addErrorMessage');

        $this->controller()->execute();
    }

    /**
     * @dataProvider outcomes
     */
    public function testTheOutcomeIsShownToTheCustomer(string $result, string $messageMethod): void
    {
        $this->authorization->method('canView')->willReturn(true);
        $this->removeSavedCard->expects(self::once())->method('execute')->with($this->info)->willReturn($result);
        $this->messages->expects(self::once())->method($messageMethod);

        self::assertSame($this->redirect, $this->controller()->execute());
    }

    public static function outcomes(): array
    {
        return [
            [RemoveSavedCard::REMOVED, 'addSuccessMessage'],
            [RemoveSavedCard::NOT_CANCELED, 'addErrorMessage'],
            [RemoveSavedCard::NO_SAVED_CARD, 'addErrorMessage'],
        ];
    }

    public function testAQliroFailureIsLoggedAndShown(): void
    {
        $this->authorization->method('canView')->willReturn(true);
        $this->removeSavedCard->method('execute')->willThrowException(new \RuntimeException('down'));
        $this->messages->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->redirect, $this->controller(self::once())->execute());
    }

    private function controller($critical = null): RemoveCard
    {
        $request = $this->createMock(Http::class);
        $request->method('getParam')->with('order_id')->willReturn('10');
        $config = $this->createMock(Config::class);
        $config->method('isActive')->willReturn(true);
        $config->method('isUseRecurring')->willReturn(true);
        $orderRepo = $this->createMock(OrderRepositoryInterface::class);
        $orderRepo->method('get')->with(10)->willReturn($this->createMock(Order::class));
        $redirects = $this->createMock(RedirectFactory::class);
        $redirects->method('create')->willReturn($this->redirect);
        $logManager = $this->createMock(LogManager::class);
        $logManager->expects($critical ?? self::never())->method('critical');

        return new RemoveCard(
            $request,
            $this->authorization,
            $this->messages,
            $orderRepo,
            $this->recurringInfoRepo,
            $this->removeSavedCard,
            $config,
            $this->createMock(ForwardFactory::class),
            $redirects,
            $logManager
        );
    }

    private function recurringInfo(): RecurringInfo
    {
        $resource = $this->createMock(RecurringInfoResource::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        return new RecurringInfo($this->createMock(Context::class), $this->createMock(Registry::class), $resource);
    }
}

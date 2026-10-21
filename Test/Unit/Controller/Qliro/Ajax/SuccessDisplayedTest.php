<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller\Qliro\Ajax;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Controller\Qliro\Ajax\SuccessDisplayed;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Success\Session;

/**
 * @see \Qliro\QliroOne\Controller\Qliro\Ajax\SuccessDisplayed::execute
 */
class SuccessDisplayedTest extends TestCase
{
    private Session&MockObject $successSession;
    private Json&MockObject $json;

    protected function setUp(): void
    {
        $this->successSession = $this->createMock(Session::class);
        $this->json = $this->createMock(Json::class);
        $this->json->method('setData')->willReturnSelf();
    }

    public function testMarksTheOrderThePageWasRenderedFor(): void
    {
        $this->successSession->method('getSuccessOrderId')->willReturn(123);
        $this->successSession->expects(self::once())->method('setSuccessDisplayed');
        $this->json->expects(self::once())->method('setData')->with(['displayed' => true]);

        self::assertSame($this->json, $this->controller('123')->execute());
    }

    /**
     * A tab left open on an older order must not end the event for the one placed since.
     */
    public function testLeavesAnotherOrderAlone(): void
    {
        $this->successSession->method('getSuccessOrderId')->willReturn(124);
        $this->successSession->expects(self::never())->method('setSuccessDisplayed');
        $this->json->expects(self::once())->method('setData')->with(['displayed' => false]);

        $this->controller('123')->execute();
    }

    public function testDoesNothingWithoutAnOrderInTheSession(): void
    {
        $this->successSession->method('getSuccessOrderId')->willReturn(null);
        $this->successSession->expects(self::never())->method('setSuccessDisplayed');

        $this->controller('')->execute();
    }

    public function testAnArrayInPlaceOfTheOrderIsRefused(): void
    {
        $this->successSession->method('getSuccessOrderId')->willReturn(123);
        $this->successSession->expects(self::never())->method('setSuccessDisplayed');

        $this->controller(['123'])->execute();
    }

    public function testDoesNothingWhileQliroIsInactive(): void
    {
        $this->successSession->method('getSuccessOrderId')->willReturn(123);
        $this->successSession->expects(self::never())->method('setSuccessDisplayed');

        $this->controller('123', false)->execute();
    }

    private function controller(string|array $order, bool $active = true): SuccessDisplayed
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('order')->willReturn($order);
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);

        $config = $this->createMock(Config::class);
        $config->method('isActive')->willReturn($active);

        return new SuccessDisplayed($request, $jsonFactory, $this->successSession, $config);
    }
}

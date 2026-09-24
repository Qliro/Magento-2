<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Plugin\Controller;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\Forward;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Plugin\Controller\KeepVaryCookie;

/**
 * @see \Qliro\QliroOne\Plugin\Controller\KeepVaryCookie
 */
class KeepVaryCookieTest extends TestCase
{
    /**
     * The page cache skips the vary cookie for a response carrying this flag, so a buyer in a
     * second currency keeps the cookie the last page gave them.
     */
    public function testFlagsTheResponseAndHandsBackTheResult(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects(self::once())->method('setMetadata')->with('NotCacheable', true);
        $result = new \stdClass();

        self::assertSame(
            $result,
            (new KeepVaryCookie($response))->afterExecute($this->createMock(ActionInterface::class), $result)
        );
    }

    /**
     * The controller a forward lands on renders the page and sets the cookie itself
     */
    public function testLeavesAForwardAlone(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects(self::never())->method('setMetadata');
        $forward = $this->createMock(Forward::class);

        self::assertSame(
            $forward,
            (new KeepVaryCookie($response))->afterExecute($this->createMock(ActionInterface::class), $forward)
        );
    }
}

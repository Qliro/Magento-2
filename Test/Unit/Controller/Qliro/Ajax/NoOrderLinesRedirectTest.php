<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Phrase;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Controller\Qliro\Ajax\GetSnippet;
use Qliro\QliroOne\Controller\Qliro\Ajax\UpdateQuote;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\NoOrderLinesException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Management;
use Qliro\QliroOne\Model\Management\HtmlSnippet;
use Qliro\QliroOne\Model\QliroOrder\LinesTotal;
use Qliro\QliroOne\Model\Quote\GuestEmail;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * A cart with nothing to pay for sends the checkout to the cart, which shows why
 */
class NoOrderLinesRedirectTest extends TestCase
{
    private const CART_URL = 'https://shop.test/checkout/cart/';
    private const MESSAGE = 'Your cart has nothing to pay for.';

    private ?array $answer = null;
    private ?int $status = null;
    private Config&MockObject $config;
    private Data&MockObject $dataHelper;
    private AjaxToken&MockObject $ajaxToken;
    private Session&MockObject $session;
    private MessageManager&MockObject $messageManager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isActive')->willReturn(true);
        $this->dataHelper = $this->createMock(Data::class);
        $this->dataHelper->method('sendPreparedPayload')->willReturnCallback(
            function (array $answer, int $status) {
                $this->answer = $answer;
                $this->status = $status;

                return $this->createMock(Json::class);
            }
        );
        $this->ajaxToken = $this->createMock(AjaxToken::class);
        $this->ajaxToken->method('verifyToken')->willReturn(true);

        $store = $this->createMock(Store::class);
        $store->method('getUrl')->with('checkout/cart')->willReturn(self::CART_URL);
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(7);
        $quote->method('getStore')->willReturn($store);
        $this->session = $this->createMock(Session::class);
        $this->session->method('getQuote')->willReturn($quote);

        $this->messageManager = $this->createMock(MessageManager::class);
        $this->messageManager->expects(self::once())->method('addNoticeMessage')->with(self::MESSAGE);
    }

    public function testTheCartRefreshSendsTheBuyerToTheCart(): void
    {
        $management = $this->createMock(Management::class);
        $management->method('setQuote')->willReturnSelf();
        $management->method('getQliroOrder')->willThrowException($this->refusal());

        (new UpdateQuote(
            $this->createMock(Http::class),
            $this->config,
            $this->dataHelper,
            $this->ajaxToken,
            $management,
            $this->session,
            $this->createMock(Manager::class),
            new LinesTotal(),
            $this->messageManager
        ))->execute();

        self::assertSame(400, $this->status);
        self::assertSame(['error' => self::MESSAGE, 'redirect' => self::CART_URL], $this->answer);
    }

    public function testTheEmbeddedSnippetSendsTheBuyerToTheCart(): void
    {
        $htmlSnippet = $this->createMock(HtmlSnippet::class);
        $htmlSnippet->method('setQuote')->willReturnSelf();
        $htmlSnippet->method('fetch')->willThrowException($this->refusal());

        (new GetSnippet(
            $this->createMock(Http::class),
            $this->config,
            $this->dataHelper,
            $this->ajaxToken,
            $htmlSnippet,
            $this->session,
            $this->createMock(Manager::class),
            $this->createMock(GuestEmail::class),
            $this->messageManager
        ))->execute();

        self::assertSame(200, $this->status);
        self::assertSame(['redirect' => self::CART_URL], $this->answer);
    }

    private function refusal(): NoOrderLinesException
    {
        return new NoOrderLinesException(new Phrase(self::MESSAGE));
    }
}

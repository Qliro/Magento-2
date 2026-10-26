<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\App\Response\Http;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
use Qliro\QliroOne\Model\Exception\UnsupportedQuoteException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Management\HtmlSnippet;
use Qliro\QliroOne\Model\Management\QliroOrder;

/**
 * @see \Qliro\QliroOne\Model\Management\HtmlSnippet
 */
class HtmlSnippetTest extends TestCase
{
    private const QUOTE_ID = 283041;
    private const SNIPPET = '<div id="qliro-root"></div>';
    private const PENDING_URL = 'https://shop.test/checkout/qliro/pending';

    private QliroOrder&MockObject $qliroOrder;
    private Manager&MockObject $logManager;
    private Http&MockObject $http;
    private LinkRepositoryInterface&MockObject $linkRepository;
    private HtmlSnippet $htmlSnippet;

    protected function setUp(): void
    {
        $this->qliroOrder = $this->createMock(QliroOrder::class);
        $this->qliroOrder->method('setQuote')->willReturnSelf();
        $this->logManager = $this->createMock(Manager::class);
        $this->http = $this->createMock(Http::class);
        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);

        $store = $this->createMock(Store::class);
        $store->method('getUrl')->with('checkout/qliro/pending')->willReturn(self::PENDING_URL);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(self::QUOTE_ID);
        $quote->method('getStore')->willReturn($store);

        $this->htmlSnippet = new HtmlSnippet(
            $this->qliroOrder,
            $this->logManager,
            $this->http,
            $this->linkRepository,
            new Escaper()
        );
        $this->htmlSnippet->setQuote($quote);
    }

    public function testFetchUnlocksTheLinkAndReturnsTheSnippet(): void
    {
        $this->linkRepository->expects(self::once())->method('unlock')->with(self::QUOTE_ID);
        $this->qliroOrderAnswers(self::SNIPPET);

        self::assertSame(self::SNIPPET, $this->htmlSnippet->fetch());
    }

    public function testFetchWithoutALinkStillReturnsTheSnippet(): void
    {
        $this->linkRepository->method('unlock')->willThrowException(new NoSuchEntityException());
        $this->qliroOrderAnswers(self::SNIPPET);

        self::assertSame(self::SNIPPET, $this->htmlSnippet->fetch());
    }

    public function testFetchReturnsAnEmptyStringForAnOrderWithoutASnippet(): void
    {
        $this->qliroOrderAnswers(null);

        self::assertSame('', $this->htmlSnippet->fetch());
    }

    public function testFetchLeavesAFailureToTheCaller(): void
    {
        $this->qliroOrder->method('get')->willThrowException(new AlreadyPlacedException());

        $this->expectException(AlreadyPlacedException::class);
        $this->htmlSnippet->fetch();
    }

    public function testGetReturnsTheSnippet(): void
    {
        $this->qliroOrderAnswers(self::SNIPPET);
        $this->http->expects(self::never())->method('setRedirect');

        self::assertSame(self::SNIPPET, $this->htmlSnippet->get());
    }

    public function testGetRedirectsAnAlreadyPlacedOrderToThePendingPage(): void
    {
        $this->qliroOrder->method('get')->willThrowException(new AlreadyPlacedException());
        $this->http->expects(self::once())->method('setRedirect')->with(self::PENDING_URL);

        self::assertSame('', $this->htmlSnippet->get());
    }

    public function testGetShowsWhyTheCartCannotBePaidFor(): void
    {
        $this->qliroOrder->method('get')->willThrowException(
            new UnsupportedQuoteException(new Phrase('Remove "<b>Gift</b>" from the cart.'))
        );

        self::assertSame(
            'Remove &quot;&lt;b&gt;Gift&lt;/b&gt;&quot; from the cart.',
            $this->htmlSnippet->get()
        );
    }

    public function testGetReturnsAStringWithTheReloadLinkWhenTheCheckoutFailsToLoad(): void
    {
        $exception = new \RuntimeException('Qliro answered 500');
        $this->qliroOrder->method('get')->willThrowException($exception);
        $this->logManager->expects(self::once())->method('critical')->with(
            'QliroOne Checkout has failed to load. Qliro answered 500',
            self::callback(static fn (array $context): bool => $context['exception'] === $exception)
        );

        $result = $this->htmlSnippet->get();

        self::assertIsString($result);
        self::assertStringContainsString(
            '<a href="javascript:;" onclick="location.reload(true)">reload page</a>',
            $result
        );
    }

    private function qliroOrderAnswers(?string $snippet): void
    {
        $order = $this->createMock(QliroOrderInterface::class);
        $order->method('getOrderHtmlSnippet')->willReturn($snippet);
        $this->qliroOrder->method('get')->willReturn($order);
    }
}

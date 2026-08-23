<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Service\Callback;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\CallbackToken;
use Qliro\QliroOne\Service\Callback\UrlBuilder;

/**
 * @see \Qliro\QliroOne\Service\Callback\UrlBuilder
 *
 * PLIN-378: Magento appends a slash after the action name, so the callback URL handed to Qliro
 * ended with ".../shippingMethods/?token=". Setups that strip that slash answer with a redirect,
 * and a client that does not resend the body on a redirect turns the callback into an empty POST.
 */
class UrlBuilderTest extends TestCase
{
    private const PATH = 'checkout/qliro_callback/shippingMethods';

    private Config&MockObject $qliroConfig;
    private Store&MockObject $store;
    private CallbackToken&MockObject $callbackToken;
    private UrlBuilder $urlBuilder;

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);
        $this->store = $this->createMock(Store::class);
        $this->callbackToken = $this->createMock(CallbackToken::class);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->store);

        $this->callbackToken->method('getToken')->willReturn('a.token');

        $this->urlBuilder = new UrlBuilder($this->qliroConfig, $storeManager, $this->callbackToken);
    }

    /**
     * The slash Magento puts between the action name and the query string is dropped.
     */
    public function testDropsTheSlashBeforeTheQueryString(): void
    {
        $this->givenStoreUrl('https://shop.test/checkout/qliro_callback/shippingMethods/?token=a.token');

        self::assertSame(
            'https://shop.test/checkout/qliro_callback/shippingMethods?token=a.token',
            $this->urlBuilder->getCallbackUrl(self::PATH)
        );
    }

    /**
     * A URL without a query string loses its trailing slash as well.
     */
    public function testDropsTheTrailingSlashWhenThereIsNoQueryString(): void
    {
        $this->givenStoreUrl('https://shop.test/checkout/qliro_callback/shippingMethods/');

        self::assertSame(
            'https://shop.test/checkout/qliro_callback/shippingMethods',
            $this->urlBuilder->getCallbackUrl(self::PATH)
        );
    }

    /**
     * A URL that already has no trailing slash is passed through untouched.
     */
    public function testLeavesAnUrlWithoutTrailingSlashAlone(): void
    {
        $this->givenStoreUrl('https://shop.test/checkout/qliro_callback/shippingMethods?token=a.token');

        self::assertSame(
            'https://shop.test/checkout/qliro_callback/shippingMethods?token=a.token',
            $this->urlBuilder->getCallbackUrl(self::PATH)
        );
    }

    /**
     * Only the path is trimmed: a slash carried by the query string itself stays.
     */
    public function testKeepsSlashesInsideTheQueryString(): void
    {
        $this->givenStoreUrl('https://shop.test/checkout/qliro_callback/shippingMethods/?token=a/b/');

        self::assertSame(
            'https://shop.test/checkout/qliro_callback/shippingMethods?token=a/b/',
            $this->urlBuilder->getCallbackUrl(self::PATH)
        );
    }

    /**
     * The token travels as a query parameter, and the debug flag is only added in debug mode.
     */
    public function testPassesTheTokenToTheStoreUrl(): void
    {
        $this->store->expects(self::once())
            ->method('getUrl')
            ->with(self::PATH, ['_query' => ['token' => 'a.token']])
            ->willReturn('https://shop.test/' . self::PATH . '/?token=a.token');

        $this->urlBuilder->getCallbackUrl(self::PATH);
    }

    /**
     * Debug mode adds the Xdebug session flag next to the token.
     */
    public function testAddsTheXdebugFlagInDebugMode(): void
    {
        $this->qliroConfig->method('isDebugMode')->willReturn(true);
        $this->qliroConfig->method('getCallbackXdebugSessionFlagName')->willReturn('PHPSTORM');

        $this->store->expects(self::once())
            ->method('getUrl')
            ->with(
                self::PATH,
                ['_query' => ['token' => 'a.token', 'XDEBUG_SESSION_START' => 'PHPSTORM']]
            )
            ->willReturn('https://shop.test/' . self::PATH . '/?token=a.token');

        $this->urlBuilder->getCallbackUrl(self::PATH);
    }

    /**
     * All callback URLs of one request share a single token.
     */
    public function testGeneratesTheTokenOnlyOnce(): void
    {
        $this->givenStoreUrl('https://shop.test/' . self::PATH . '/?token=a.token');

        $this->callbackToken->expects(self::once())->method('getToken');

        $this->urlBuilder->getCallbackUrl(self::PATH);
        $this->urlBuilder->getCallbackUrl('checkout/qliro_callback/validate');
    }

    /**
     * The token is signed with the store's API credentials, and this service is a singleton the
     * recurring orders cron reuses while emulating every store, so one cached token for the whole
     * process would hand stores 2..n a token signed by store 1 and every push would be rejected.
     */
    public function testGeneratesOneTokenPerStore(): void
    {
        $firstStore = $this->createMock(Store::class);
        $firstStore->method('getId')->willReturn(1);

        $secondStore = $this->createMock(Store::class);
        $secondStore->method('getId')->willReturn(2);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnOnConsecutiveCalls(
            $firstStore,
            $firstStore,
            $secondStore,
            $secondStore
        );

        $callbackToken = $this->createMock(CallbackToken::class);
        $callbackToken->expects(self::exactly(2))
            ->method('getToken')
            ->willReturnOnConsecutiveCalls('first.token', 'second.token');

        $urlBuilder = new UrlBuilder($this->qliroConfig, $storeManager, $callbackToken);

        $firstStore->expects(self::once())
            ->method('getUrl')
            ->with(self::PATH, ['_query' => ['token' => 'first.token']])
            ->willReturn('https://shop.test/' . self::PATH);
        $secondStore->expects(self::once())
            ->method('getUrl')
            ->with(self::PATH, ['_query' => ['token' => 'second.token']])
            ->willReturn('https://shop.test/' . self::PATH);

        $urlBuilder->getCallbackUrl(self::PATH);
        $urlBuilder->getCallbackUrl(self::PATH);
    }

    /**
     * The configured callback base URI is used verbatim, it never had a trailing slash to lose.
     */
    public function testUsesTheConfiguredCallbackBaseUri(): void
    {
        $this->qliroConfig->method('redirectCallbacks')->willReturn(true);
        $this->qliroConfig->method('getCallbackUri')->willReturn('https://callbacks.test/');

        $this->store->expects(self::never())->method('getUrl');

        self::assertSame(
            'https://callbacks.test/checkout/qliro_callback/shippingMethods?token=a.token',
            $this->urlBuilder->getCallbackUrl(self::PATH)
        );
    }

    /**
     * HTTP auth credentials are applied to the URL the slash was already removed from.
     */
    public function testAppliesHttpAuthCredentials(): void
    {
        $this->qliroConfig->method('isHttpAuthEnabled')->willReturn(true);
        $this->qliroConfig->method('getCallbackHttpAuthUsername')->willReturn('user name');
        $this->qliroConfig->method('getCallbackHttpAuthPassword')->willReturn('pa/ss');
        $this->givenStoreUrl('https://shop.test/' . self::PATH . '/?token=a.token');

        self::assertSame(
            'https://user+name:pa%2Fss@shop.test/' . self::PATH . '?token=a.token',
            $this->urlBuilder->getCallbackUrl(self::PATH)
        );
    }

    /**
     * Let the store report the given URL for any path
     *
     * @param string $url
     * @return void
     */
    private function givenStoreUrl(string $url): void
    {
        $this->store->method('getUrl')->willReturn($url);
    }
}

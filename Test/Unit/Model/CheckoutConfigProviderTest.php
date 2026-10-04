<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\CheckoutConfigProvider;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\Management\CountrySelect;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringPaymentsDataService;

/**
 * @see \Qliro\QliroOne\Model\CheckoutConfigProvider
 */
class CheckoutConfigProviderTest extends TestCase
{
    private Http&MockObject $request;
    private CheckoutConfigProvider $provider;

    protected function setUp(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getUrl')->willReturn('https://example.com/checkout/qliro/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $ajaxToken = $this->createMock(AjaxToken::class);
        $ajaxToken->method('setQuote')->willReturnSelf();
        $ajaxToken->method('getToken')->willReturn('token');

        $checkoutSession = $this->createMock(Session::class);
        $checkoutSession->method('getQuote')->willReturn($this->createMock(Quote::class));

        $this->request = $this->createMock(Http::class);

        $this->provider = new CheckoutConfigProvider(
            $storeManager,
            $ajaxToken,
            $checkoutSession,
            $this->createMock(Config::class),
            $this->createMock(Fee::class),
            $this->createMock(CountrySelect::class),
            $this->createMock(RecurringPaymentsDataService::class),
            $this->request
        );
    }

    public function testItFlagsTheQliroCheckoutPage(): void
    {
        $this->request->method('getFullActionName')->willReturn('checkout_qliro_index');

        $this->assertTrue($this->provider->getConfig()['qliro']['isQliroCheckoutPage']);
    }

    /**
     * The native checkout and the cart page collect the same config, and must keep their shipping step
     *
     * @dataProvider otherPages
     */
    public function testItDoesNotFlagAnotherPage(string $fullActionName): void
    {
        $this->request->method('getFullActionName')->willReturn($fullActionName);

        $this->assertFalse($this->provider->getConfig()['qliro']['isQliroCheckoutPage']);
    }

    public static function otherPages(): array
    {
        return [
            'native checkout' => ['checkout_index_index'],
            'cart' => ['checkout_cart_index'],
        ];
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model;

use Magento\Checkout\Model\Session;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Model\Method\Adapter;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\CheckoutConfigProvider;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Data\PaymentRecurringInfo;
use Qliro\QliroOne\Model\Data\PaymentRecurringInfoFactory;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\Management\CountrySelect;
use Qliro\QliroOne\Model\RecurringInfoFactory;
use Qliro\QliroOne\Model\RecurringInfoRepository;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringPaymentsDataService;

/**
 * @see \Qliro\QliroOne\Model\CheckoutConfigProvider
 */
class CheckoutConfigProviderTest extends TestCase
{
    private Http&MockObject $request;
    private Config&MockObject $qliroConfig;
    private CheckoutConfigProvider $provider;
    private StoreManagerInterface&MockObject $storeManager;
    private AjaxToken&MockObject $ajaxToken;
    private Session&MockObject $checkoutSession;

    protected function setUp(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getUrl')->willReturn('https://example.com/checkout/qliro/');
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->ajaxToken = $this->createMock(AjaxToken::class);
        $this->ajaxToken->method('setQuote')->willReturnSelf();
        $this->ajaxToken->method('getToken')->willReturn('token');

        $this->checkoutSession = $this->createMock(Session::class);
        $quote = $this->createMock(Quote::class);
        $quote->method('getPayment')->willReturn($this->createMock(Payment::class));
        $this->checkoutSession->method('getQuote')->willReturn($quote);

        $this->request = $this->createMock(Http::class);
        $this->qliroConfig = $this->createMock(Config::class);

        $this->provider = new CheckoutConfigProvider(
            $this->storeManager,
            $this->ajaxToken,
            $this->checkoutSession,
            $this->qliroConfig,
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

    public function testItSaysIframeForTheEmbeddedModeInTheNativeCheckout(): void
    {
        $this->request->method('getFullActionName')->willReturn('checkout_index_index');
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);

        $this->assertSame('iframe', $this->provider->getConfig()['qliro']['paymentMethodRenderMode']);
    }

    public function testItSaysRedirectWithoutTheEmbeddedMode(): void
    {
        $this->request->method('getFullActionName')->willReturn('checkout_index_index');
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(false);

        $this->assertSame('redirect', $this->provider->getConfig()['qliro']['paymentMethodRenderMode']);
    }

    /**
     * The page stays reachable in the embedded mode, by a bookmark or an old link, and has to sync
     * the address and the delivery like any other visit to it
     */
    public function testItSaysRedirectOnTheQliroCheckoutPageInTheEmbeddedMode(): void
    {
        $this->request->method('getFullActionName')->willReturn('checkout_qliro_index');
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);

        $this->assertSame('redirect', $this->provider->getConfig()['qliro']['paymentMethodRenderMode']);
    }

    /**
     * Recurring payments turned on with config:set or a config dump leave the options unsaved
     */
    public function testItOffersNoFrequencyWhenRecurringIsOnAndNoOptionsAreSaved(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('getConfigData')->willReturnCallback(
            static fn (string $field) => $field === Config::QLIROONE_RECURRING_ENABLE ? '1' : null
        );
        $config = new Config(
            $adapter,
            $this->createMock(ScopeConfigInterface::class),
            new Json(),
            $this->createMock(DirectoryHelper::class),
            $this->createMock(CountryCollectionFactory::class)
        );
        $recurringInfoFactory = $this->createMock(PaymentRecurringInfoFactory::class);
        $recurringInfoFactory->method('create')->willReturn($this->createMock(PaymentRecurringInfo::class));
        $provider = new CheckoutConfigProvider(
            $this->storeManager,
            $this->ajaxToken,
            $this->checkoutSession,
            $config,
            $this->createMock(Fee::class),
            $this->createMock(CountrySelect::class),
            new RecurringPaymentsDataService(
                $recurringInfoFactory,
                $this->createMock(RecurringInfoFactory::class),
                $this->createMock(RecurringInfoRepository::class),
                new Json()
            ),
            $this->request
        );

        $recurringOrder = $provider->getConfig()['qliro']['recurringOrder'];

        $this->assertTrue($recurringOrder['enabled']);
        $this->assertSame([], $recurringOrder['availableFrequencyOptions']);
    }

    public static function otherPages(): array
    {
        return [
            'native checkout' => ['checkout_index_index'],
            'cart' => ['checkout_cart_index'],
        ];
    }
}

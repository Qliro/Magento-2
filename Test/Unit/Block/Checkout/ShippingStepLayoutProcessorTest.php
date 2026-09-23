<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Block\Checkout;

use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Block\Checkout\ShippingStepLayoutProcessor;
use Qliro\QliroOne\Model\Config;

/**
 * @see \Qliro\QliroOne\Block\Checkout\ShippingStepLayoutProcessor
 */
class ShippingStepLayoutProcessorTest extends TestCase
{
    private Http&MockObject $request;
    private Config&MockObject $config;
    private ShippingStepLayoutProcessor $processor;

    protected function setUp(): void
    {
        $this->request = $this->createMock(Http::class);
        $this->config = $this->createMock(Config::class);
        $this->processor = new ShippingStepLayoutProcessor($this->request, $this->config);
    }

    public function testItEmptiesTheShippingTemplateOnTheQliroCheckout(): void
    {
        $this->request->method('getFullActionName')->willReturn('checkout_qliro_index');
        $this->config->method('isHideNativeShippingStep')->willReturn(true);

        $jsLayout = $this->processor->process($this->jsLayout());

        $shippingAddress = $jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress'];

        $this->assertSame('', $shippingAddress['config']['template']);
        $this->assertArrayHasKey('children', $shippingAddress, 'The component itself must stay in the layout');
    }

    public function testItLeavesTheNativeCheckoutAlone(): void
    {
        $this->request->method('getFullActionName')->willReturn('checkout_index_index');
        $this->config->method('isHideNativeShippingStep')->willReturn(true);

        $this->assertSame($this->jsLayout(), $this->processor->process($this->jsLayout()));
    }

    public function testItLeavesTheStepAloneWhenTheMerchantKeepsIt(): void
    {
        $this->request->method('getFullActionName')->willReturn('checkout_qliro_index');
        $this->config->method('isHideNativeShippingStep')->willReturn(false);

        $this->assertSame($this->jsLayout(), $this->processor->process($this->jsLayout()));
    }

    public function testItAddsNothingWhenTheComponentIsAbsent(): void
    {
        $this->request->method('getFullActionName')->willReturn('checkout_qliro_index');
        $this->config->method('isHideNativeShippingStep')->willReturn(true);

        $jsLayout = ['components' => ['checkout' => ['children' => ['steps' => ['children' => []]]]]];

        $this->assertSame($jsLayout, $this->processor->process($jsLayout));
    }

    /**
     * Magento_Checkout's frontend layoutProcessors argument replaces a global one, so only a frontend registration runs
     */
    public function testItIsRegisteredInTheFrontendArea(): void
    {
        $query = '//type[@name="Magento\Checkout\Block\Onepage"]//item[@name="qliroone_shipping_step"]';
        $etc = dirname(__DIR__, 4) . '/etc';

        $this->assertCount(1, simplexml_load_file($etc . '/frontend/di.xml')->xpath($query));
        $this->assertCount(0, simplexml_load_file($etc . '/di.xml')->xpath($query));
    }

    /**
     * The part of the checkout layout this processor reaches into
     *
     * @return array
     */
    private function jsLayout(): array
    {
        return [
            'components' => [
                'checkout' => [
                    'children' => [
                        'steps' => [
                            'children' => [
                                'shipping-step' => [
                                    'children' => [
                                        'shippingAddress' => [
                                            'children' => [
                                                'shipping-address-fieldset' => [],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

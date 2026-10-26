<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\ViewModel\Adminhtml\Order\View;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\AddressFactory;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection as TrackCollection;
use Magento\Shipping\Helper\Data as ShippingHelper;
use Magento\Tax\Helper\Data as TaxHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Management;
use Qliro\QliroOne\ViewModel\Adminhtml\Order\View\Shipping;

/**
 * @see \Qliro\QliroOne\ViewModel\Adminhtml\Order\View\Shipping
 */
class ShippingTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../../../view/adminhtml/templates/order/view/shipping/info.phtml';

    private Shipping $viewModel;

    protected function setUp(): void
    {
        $dataObjectFactory = $this->createMock(DataObjectFactory::class);
        $dataObjectFactory->method('create')->willReturnCallback(fn () => new DataObject());
        $addressFactory = $this->createMock(AddressFactory::class);
        $addressFactory->method('create')->willReturnCallback(fn () => $this->createPartialMock(Address::class, []));

        $this->viewModel = new Shipping(
            $dataObjectFactory,
            $addressFactory,
            $this->createMock(LinkRepositoryInterface::class),
            $this->createMock(Management::class),
            $this->createMock(OrderRepositoryInterface::class)
        );
    }

    public static function shippingMethods(): array
    {
        return [
            'none' => [null, false, false],
            'empty' => ['', false, false],
            'other carrier' => ['flatrate_flatrate', false, false],
            'carrier without method' => ['freeshipping', false, false],
            'unifaun' => ['qlirounifaun_postnord', true, false],
            'ingrid' => ['qliroingrid_standard', false, true],
        ];
    }

    /**
     * @dataProvider shippingMethods
     */
    public function testMatchesTheCarrier(?string $method, bool $unifaun, bool $ingrid): void
    {
        $order = $this->order($method);

        self::assertSame($unifaun, $this->viewModel->isQliroUnifaunShipping($order));
        self::assertSame($ingrid, $this->viewModel->isQliroIngridShipping($order));
    }

    public function testHasNoShippingDetailsWithoutAShippingMethod(): void
    {
        $order = $this->order(null);

        self::assertSame([], $this->viewModel->getShippingLocationInfo($order)->getData());
        self::assertSame([], $this->viewModel->getIngridShippingInfo($order));
    }

    public function testReadsTheUnifaunLocation(): void
    {
        $order = $this->order('qlirounifaun_postnord', [
            'qliroone_shipping_info' => ['payload' => ['agent' => ['name' => 'Pickup point']]],
        ]);

        self::assertSame('Pickup point', $this->viewModel->getShippingLocationInfo($order)->getName());
    }

    /**
     * A pickup point replaces the shipping address in the view, and only its text fields are kept.
     */
    public function testShowsTheUnifaunPickupPointAsTheAddress(): void
    {
        $order = $this->order('qlirounifaun_postnord', [
            'qliroone_shipping_info' => ['payload' => ['agent' => [
                'name' => 'Pickup point',
                'address1' => 'Storgatan 1',
                'zipcode' => '11122',
                'city' => 'Stockholm',
                'country' => 'SE',
                'address2' => ['not text'],
            ]]],
        ]);

        self::assertNull($this->viewModel->getShippingLocationInfo($order)->getData('address2'));
        self::assertSame(['Storgatan 1'], $this->viewModel->getStreetAddressLines($order));
        self::assertSame('11122 Stockholm, SE', $this->viewModel->getPostalAddressLine($order));
    }

    public function testHasNoShippingDetailsWithoutAPayment(): void
    {
        $unifaun = $this->order('qlirounifaun_x', null);
        $ingrid = $this->order('qliroingrid_x', null);

        self::assertSame([], $this->viewModel->getShippingLocationInfo($unifaun)->getData());
        self::assertSame([], $this->viewModel->getIngridShippingInfo($ingrid));
    }

    public function testReadsTheIngridSession(): void
    {
        $order = $this->order('qliroingrid_standard', [
            'qliroone_shipping_info' => ['payload' => ['session' => [
                'checkout_session_id' => 'cs-1',
                'delivery_groups' => [[
                    'tos_id' => 'tos-1',
                    'category' => ['name' => 'Home delivery'],
                    'shipping' => [
                        'carrier' => 'PostNord',
                        'carrier_product_id' => 'pn-1',
                        'delivery_addons' => [['external_addon_id' => 'a1'], ['external_addon_id' => 'a2']],
                    ],
                    'addresses' => ['location' => ['name' => 'Pickup point', 'external_id' => 'pp-1']],
                ]],
            ]]],
        ]);

        self::assertSame([
            'checkout_session_id' => 'cs-1',
            'tos_id' => 'tos-1',
            'category' => 'Home delivery',
            'carrier' => 'PostNord',
            'carrier_product_id' => 'pn-1',
            'location' => 'Pickup point',
            'pickup_point_id' => 'pp-1',
            'delivery_addons' => 'a1, a2',
        ], $this->viewModel->getIngridShippingInfo($order));
    }

    public static function incompleteShippingInfo(): array
    {
        return [
            'string' => ['not decoded'],
            'no session' => [['payload' => []]],
            'session not an array' => [['payload' => ['session' => 'cs-1']]],
        ];
    }

    /**
     * @dataProvider incompleteShippingInfo
     */
    public function testSkipsIncompleteIngridInfo(mixed $info): void
    {
        $order = $this->order('qliroingrid_standard', ['qliroone_shipping_info' => $info]);

        self::assertSame([], $this->viewModel->getIngridShippingInfo($order));
    }

    public function testFillsMissingIngridFieldsWithEmptyValues(): void
    {
        $order = $this->order('qliroingrid_standard', [
            'qliroone_shipping_info' => ['payload' => ['session' => ['delivery_groups' => [['tos_id' => 'tos-1']]]]],
        ]);

        self::assertSame([
            'checkout_session_id' => '',
            'tos_id' => 'tos-1',
            'category' => '',
            'carrier' => '',
            'carrier_product_id' => '',
        ], $this->viewModel->getIngridShippingInfo($order));
    }

    /**
     * Support needs the session id to find the order in Ingrid, even without a delivery group.
     */
    public function testKeepsTheSessionIdWithoutADeliveryGroup(): void
    {
        $order = $this->order('qliroingrid_standard', [
            'qliroone_shipping_info' => ['payload' => ['session' => ['checkout_session_id' => 'cs-1']]],
        ]);

        self::assertSame([
            'checkout_session_id' => 'cs-1',
            'tos_id' => '',
            'category' => '',
            'carrier' => '',
            'carrier_product_id' => '',
        ], $this->viewModel->getIngridShippingInfo($order));
    }

    /**
     * The template escapes each value, and an array there fails the page.
     */
    public function testShowsValuesThatAreNotTextAsEmpty(): void
    {
        $order = $this->order('qliroingrid_standard', [
            'qliroone_shipping_info' => ['payload' => ['session' => [
                'checkout_session_id' => ['cs-1'],
                'delivery_groups' => [[
                    'tos_id' => 7,
                    'category' => ['name' => ['Home']],
                    'shipping' => ['delivery_addons' => [['external_addon_id' => ['a1']], 'a2']],
                ]],
            ]]],
        ]);

        self::assertSame([
            'checkout_session_id' => '',
            'tos_id' => '7',
            'category' => '',
            'carrier' => '',
            'carrier_product_id' => '',
            'delivery_addons' => 'a2',
        ], $this->viewModel->getIngridShippingInfo($order));
    }

    public function testSkipsUnifaunInfoThatIsNotAnArray(): void
    {
        $order = $this->order('qlirounifaun_postnord', ['qliroone_shipping_info' => 'not decoded']);

        self::assertSame([], $this->viewModel->getShippingLocationInfo($order)->getData());
    }

    public function testHasNoAddressLinesWithoutAShippingAddress(): void
    {
        $order = $this->order('qlirounifaun_postnord');
        $order->method('getShippingAddress')->willReturn(null);

        self::assertSame([], $this->viewModel->getStreetAddressLines($order));
        self::assertSame('', $this->viewModel->getPostalAddressLine($order));
    }

    /**
     * The module replaces core's order_shipping_view block, so this is every order's view.
     */
    public function testRendersAnOrderWithoutAShippingMethod(): void
    {
        $html = $this->render($this->order(null));

        self::assertStringContainsString('Shipping &amp; Handling Information', $html);
        self::assertStringContainsString('No shipping information available', $html);
    }

    /**
     * An imported order can carry a description without a method.
     */
    public function testRendersTheDescriptionOfAnOrderWithoutAShippingMethod(): void
    {
        $order = $this->order(null, [], 'Flat Rate - Fixed');

        $html = $this->render($order);

        self::assertStringContainsString('Flat Rate - Fixed', $html);
        self::assertStringContainsString('10,00 kr', $html);
        self::assertStringNotContainsString('No shipping information available', $html);
    }

    public function testRendersNothingForAVirtualOrder(): void
    {
        $order = $this->order(null);
        $order->method('getIsVirtual')->willReturn(true);

        self::assertSame('', trim($this->render($order)));
    }

    private function order(
        ?string $shippingMethod,
        ?array $additionalInformation = [],
        ?string $description = null
    ): Order&MockObject
    {
        $payment = null;
        if (null !== $additionalInformation) {
            $payment = $this->createMock(Payment::class);
            $payment->method('getAdditionalInformation')->willReturn($additionalInformation);
        }

        $tracks = $this->createMock(TrackCollection::class);
        $tracks->method('count')->willReturn(0);

        $order = $this->createPartialMock(
            Order::class,
            ['getIsVirtual', 'getPayment', 'getTracksCollection', 'getShippingDescription', 'getShippingAddress']
        );
        $order->setData('shipping_method', $shippingMethod);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getTracksCollection')->willReturn($tracks);
        $order->method('getShippingDescription')->willReturn($description);

        return $order;
    }

    private function render(Order $order): string
    {
        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('displayShippingPriceIncludingTax')->willReturn(false);
        $taxHelper->method('displayShippingBothPrices')->willReturn(false);
        $data = ['shippingHelper' => $this->createMock(ShippingHelper::class), 'taxHelper' => $taxHelper];

        $block = new class ($order, $this->viewModel, $data) {
            public function __construct(private Order $order, private Shipping $viewModel, private array $data)
            {
            }

            public function displayPriceAttribute(): string
            {
                return '10,00 kr';
            }

            public function displayShippingPriceInclTax(): string
            {
                return '12,50 kr';
            }

            public function getOrder(): Order
            {
                return $this->order;
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function getViewModel(): Shipping
            {
                return $this->viewModel;
            }

            public function getChildBlock(string $alias): bool
            {
                return false;
            }
        };
        $escaper = new Escaper();
        $secureRenderer = $this->createMock(SecureHtmlRenderer::class);

        ob_start();
        try {
            (static function () use ($block, $escaper, $secureRenderer): void {
                include self::TEMPLATE;
            })();
        } finally {
            $html = (string)ob_get_clean();
        }

        return $html;
    }
}

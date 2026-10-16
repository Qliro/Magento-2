<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Tax\Helper\Data as TaxHelper;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Controller\Qliro\Ajax\UpdateShippingPrice;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * The price Qliro's widget posts reaches the float return under strict types (PLIN-371)
 */
class UpdateShippingPriceTest extends TestCase
{
    public function testANumericStringPriceIsReadAsAFloat(): void
    {
        $this->assertSame(49.0, $this->shippingPrice(['newShippingPrice' => '49.00']));
    }

    public function testAJsonNumberIsReadAsItCame(): void
    {
        $this->assertSame(49.5, $this->shippingPrice(['newShippingPrice' => 49.5]));
    }

    /**
     * Refused rather than read as a price of zero, the controller answers it as a bad request
     */
    public function testANonNumericPriceStillFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->shippingPrice(['newShippingPrice' => 'free']);
    }

    private function shippingPrice(array $payload): float
    {
        $dataHelper = $this->createMock(Data::class);
        $dataHelper->method('readPreparedPayload')->willReturn($payload);

        $address = $this->createMock(Address::class);
        $address->method('getAppliedTaxes')->willReturn([]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);
        $session = $this->createMock(Session::class);
        $session->method('getQuote')->willReturn($quote);

        $controller = new UpdateShippingPrice(
            $this->createMock(Http::class),
            $this->createMock(Config::class),
            $dataHelper,
            $this->createMock(AjaxToken::class),
            $this->createMock(ManagementInterface::class),
            $session,
            $this->createMock(Manager::class),
            $this->createMock(ProductMetadataInterface::class),
            $this->createMock(TaxHelper::class),
            $this->createMock(LinkRepositoryInterface::class)
        );

        return (new \ReflectionMethod($controller, 'getShippingPrice'))->invoke($controller);
    }
}

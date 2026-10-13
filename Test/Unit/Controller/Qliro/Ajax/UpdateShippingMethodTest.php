<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Controller\Qliro\Ajax;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\ProductMetadata;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Tax\Helper\Data as TaxHelper;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Controller\Qliro\Ajax\UpdateShippingMethod;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Carrier\Ingrid;
use Qliro\QliroOne\Model\Carrier\Unifaun;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Management;
use Qliro\QliroOne\Model\Security\AjaxToken;

/**
 * The secondary option the customer selects in Qliro's widget reaches updateShippingMethod()
 */
class UpdateShippingMethodTest extends TestCase
{
    private const PAYLOAD = [
        'method' => 'flatrate_flatrate',
        'secondaryOption' => 'pickup-point-42',
        'methodName' => 'Ingrid pickup',
        'price' => 49,
        'priceExVat' => 39.2,
    ];

    public function testACustomShippingMethodPassesTheSecondaryOption(): void
    {
        $this->assertSame(
            ['flatrate_flatrate', 'pickup-point-42', 49],
            $this->updateArguments(self::PAYLOAD)
        );
    }

    public function testACustomShippingMethodWithoutASecondaryOptionPassesNull(): void
    {
        $payload = self::PAYLOAD;
        unset($payload['secondaryOption']);

        $this->assertSame(['flatrate_flatrate', null, 49], $this->updateArguments($payload));
    }

    public function testUnifaunStillPassesTheSecondaryOption(): void
    {
        $this->assertSame(
            [Unifaun::QLIRO_UNIFAUN_SHIPPING_CODE, 'pickup-point-42', 49],
            $this->updateArguments(self::PAYLOAD, unifaun: true)
        );
    }

    public function testIngridStillPassesTheMethodName(): void
    {
        $this->assertSame(
            [Ingrid::QLIRO_INGRID_SHIPPING_CODE, 'Ingrid pickup', 49],
            $this->updateArguments(self::PAYLOAD, ingrid: true)
        );
    }

    private function updateArguments(array $payload, bool $unifaun = false, bool $ingrid = false): array
    {
        $config = $this->createMock(Config::class);
        $config->method('isActive')->willReturn(true);
        $config->method('isUnifaunEnabled')->willReturn($unifaun);
        $config->method('isIngridEnabled')->willReturn($ingrid);

        $dataHelper = $this->createMock(Data::class);
        $dataHelper->method('readPreparedPayload')->willReturn($payload);
        $dataHelper->method('sendPreparedPayload')->willReturn($this->createMock(Json::class));

        $ajaxToken = $this->createMock(AjaxToken::class);
        $ajaxToken->method('verifyToken')->willReturn(true);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(1);
        $session = $this->createMock(Session::class);
        $session->method('getQuote')->willReturn($quote);

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQuoteId')->willThrowException(new NoSuchEntityException());

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getEdition')->willReturn(ProductMetadata::EDITION_NAME);
        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('shippingPriceIncludesTax')->willReturn(true);

        $arguments = null;
        $management = $this->createMock(Management::class);
        $management->method('setQuote')->willReturnSelf();
        $management->expects($this->once())
            ->method('updateShippingMethod')
            ->willReturnCallback(function (...$args) use (&$arguments) {
                $arguments = $args;
                return true;
            });

        $controller = new UpdateShippingMethod(
            $this->createMock(Http::class),
            $config,
            $dataHelper,
            $ajaxToken,
            $management,
            $session,
            $this->createMock(Manager::class),
            $productMetadata,
            $taxHelper,
            $linkRepository
        );
        $controller->execute();

        return $arguments;
    }
}

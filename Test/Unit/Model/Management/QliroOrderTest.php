<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\QliroOrder;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromValidateConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * @see \Qliro\QliroOne\Model\Management\QliroOrder
 */
class QliroOrderTest extends TestCase
{
    private const QLIRO_ORDER_ID = 276402736;

    private QuoteManagement&MockObject $quoteManagement;
    private QuoteFromOrderConverter&MockObject $quoteFromOrderConverter;
    private MerchantInterface&MockObject $merchantApi;
    private QliroOrder $management;

    protected function setUp(): void
    {
        $this->quoteManagement = $this->createMock(QuoteManagement::class);
        $this->quoteFromOrderConverter = $this->createMock(QuoteFromOrderConverter::class);
        $this->merchantApi = $this->createMock(MerchantInterface::class);

        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $link->method('getQuoteId')->willReturn(282629);
        $link->method('getOrderId')->willReturn(null);

        $this->quoteManagement->method('setQuote')->willReturnSelf();
        $this->quoteManagement->method('getLinkFromQuote')->willReturn($link);

        $qliroOrder = $this->createMock(QliroOrderInterface::class);
        $qliroOrder->method('isPlaced')->willReturn(false);
        $qliroOrder->method('isRefused')->willReturn(false);
        $this->merchantApi->method('getOrder')->willReturn($qliroOrder);

        $lock = $this->createMock(Lock::class);
        $lock->method('lock')->willReturn(true);

        $this->management = new QliroOrder(
            $this->createMock(Config::class),
            $this->merchantApi,
            $this->createMock(OrderManagementInterface::class),
            $this->createMock(UpdateRequestBuilder::class),
            $this->createMock(ValidateOrderBuilder::class),
            $this->createMock(QuoteFromValidateConverter::class),
            $this->quoteFromOrderConverter,
            $this->createMock(LinkRepositoryInterface::class),
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(ContainerMapper::class),
            $this->createMock(LogManager::class),
            $lock,
            $this->createMock(OrderManagementStatusInterfaceFactory::class),
            $this->createMock(OrderManagementStatusRepositoryInterface::class),
            $this->quoteManagement
        );
        $this->management->setQuote($this->createMock(\Magento\Quote\Model\Quote::class));
    }

    /**
     * The order update carrying AvailableShippingMethods is pushed by getLinkFromQuote(), which
     * runs before the order is fetched. Qliro masks the address in the browser payload, so this
     * fetch is where it first becomes known, and without pushing again the checkout keeps an
     * empty shipping method list until the page is reloaded. This is the regression the fix
     * targets, so the ordering is pinned here and not only in the converter.
     */
    public function testPushesTheOrderUpdateAgainWhenTheFetchChangedTheQuote(): void
    {
        $this->quoteFromOrderConverter->method('convert')->willReturn(true);

        $this->quoteManagement->expects(self::once())
            ->method('update')
            ->with(self::QLIRO_ORDER_ID);

        $this->management->get();
    }

    /**
     * A fetch that brought nothing new must not cost an extra call to Qliro, otherwise every
     * checkout request would push the same order twice.
     */
    public function testDoesNotPushAgainWhenTheFetchChangedNothing(): void
    {
        $this->quoteFromOrderConverter->method('convert')->willReturn(false);

        $this->quoteManagement->expects(self::never())->method('update');

        $this->management->get();
    }

    /**
     * The second push happens after the quote was recalculated and saved, otherwise it would
     * send the shipping methods of the address-less quote all over again.
     */
    public function testPushesAfterTheQuoteHasBeenRecalculated(): void
    {
        $this->quoteFromOrderConverter->method('convert')->willReturn(true);

        $calls = [];
        $this->quoteManagement->method('recalculateAndSaveQuote')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'recalculate';
            });
        $this->quoteManagement->method('update')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'update';
            });

        $this->management->get();

        self::assertSame(['recalculate', 'update'], $calls);
    }
}

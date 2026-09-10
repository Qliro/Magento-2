<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote as QuoteModel;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Management\ShippingMethod;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromShippingMethodsConverter;

/**
 * @see \Qliro\QliroOne\Model\Management\ShippingMethod
 */
class ShippingMethodTest extends TestCase
{
    private const QLIRO_ORDER_ID = 278811373;
    private const QUOTE_STORE_ID = 4;
    private const DEFAULT_STORE_ID = 1;

    /**
     * What `$storeManager->getStore()->getId()` answers, so a test can let the emulation take
     * effect the way the real one does, or refuse it the way a nested one is refused.
     */
    private int $currentStoreId = self::DEFAULT_STORE_ID;

    /**
     * What the quote answers for its own store view, so a test can hand over one that states none.
     */
    private int $quoteStoreId = self::QUOTE_STORE_ID;

    private Emulation&MockObject $storeEmulation;
    private ShippingMethodsBuilder&MockObject $shippingMethodsBuilder;
    private QuoteFromShippingMethodsConverter&MockObject $converter;
    private UpdateShippingMethodsNotificationInterface&MockObject $updateContainer;
    private ShippingMethod $management;

    protected function setUp(): void
    {
        $this->storeEmulation = $this->createMock(Emulation::class);

        $currentStore = $this->createMock(StoreInterface::class);
        $currentStore->method('getId')->willReturnCallback(fn () => $this->currentStoreId);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($currentStore);

        $link = $this->createMock(LinkInterface::class);
        $link->method('getQuoteId')->willReturn(283041);
        $link->method('getReference')->willReturn('SXx4PQ');

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQliroOrderId')->willReturn($link);

        $quote = $this->createMock(QuoteModel::class);
        $quote->method('getStoreId')->willReturnCallback(fn () => $this->quoteStoreId);

        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('get')->willReturn($quote);

        $quoteManagement = $this->createMock(QuoteManagement::class);
        $quoteManagement->method('setQuote')->willReturnSelf();

        $this->converter = $this->createMock(QuoteFromShippingMethodsConverter::class);

        $this->shippingMethodsBuilder = $this->createMock(ShippingMethodsBuilder::class);
        $this->shippingMethodsBuilder->method('setQuote')->willReturnSelf();
        $this->shippingMethodsBuilder->method('create')
            ->willReturn($this->createMock(UpdateShippingMethodsResponseInterface::class));

        $this->updateContainer = $this->createMock(UpdateShippingMethodsNotificationInterface::class);
        $this->updateContainer->method('getOrderId')->willReturn(self::QLIRO_ORDER_ID);

        $this->management = new ShippingMethod(
            $this->shippingMethodsBuilder,
            $this->converter,
            $linkRepository,
            $quoteRepository,
            $this->createMock(ContainerMapper::class),
            $this->createMock(LogManager::class),
            $this->createMock(ManagerInterface::class),
            $this->createMock(ScopeConfig::class),
            $quoteManagement,
            $storeManager,
            $this->storeEmulation
        );
    }

    /**
     * Let the emulation move the current store the way the real one does
     *
     * @return void
     */
    private function letTheEmulationTakeEffect(): void
    {
        $this->storeEmulation->method('startEnvironmentEmulation')
            ->willReturnCallback(function (): void {
                $this->currentStoreId = self::QUOTE_STORE_ID;
            });
    }

    /**
     * Qliro calls this back server to server, so the request has no session, and its URL carries a
     * store code only when `web/url/use_store` is on, which Magento ships off, so by default it
     * lands in the default store view. Rating a quote from another store view there
     * prices the delivery in the wrong currency and names it in the wrong language, so the quote's
     * own store has to be emulated for the whole build.
     */
    public function testEmulatesTheQuoteStoreWhenTheRequestRunsInAnotherOne(): void
    {
        $this->storeEmulation->expects(self::once())
            ->method('startEnvironmentEmulation')
            ->with(self::QUOTE_STORE_ID);

        $this->management->get($this->updateContainer);
    }

    /**
     * The build happens inside the emulation, not before or after it, otherwise the rates would
     * still be collected and priced in the store view the request resolved to.
     */
    public function testBuildsTheResponseWhileTheEmulationIsActive(): void
    {
        $calls = [];
        $this->storeEmulation->method('startEnvironmentEmulation')
            ->willReturnCallback(function () use (&$calls): void {
                $this->currentStoreId = self::QUOTE_STORE_ID;
                $calls[] = 'start';
            });
        $this->converter->method('convert')
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'convert';
            });
        $this->shippingMethodsBuilder->method('create')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'create';

                return $this->createMock(UpdateShippingMethodsResponseInterface::class);
            });
        $this->storeEmulation->method('stopEnvironmentEmulation')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'stop';

                return $this->storeEmulation;
            });

        $this->management->get($this->updateContainer);

        self::assertSame(['start', 'convert', 'create', 'stop'], $calls);
    }

    /**
     * A build that throws must still hand the store view back, otherwise the emulation leaks into
     * the rest of the request and every later read answers for the wrong store.
     */
    public function testStopsTheEmulationWhenTheBuildThrows(): void
    {
        $this->letTheEmulationTakeEffect();
        $this->shippingMethodsBuilder->method('create')
            ->willThrowException(new \RuntimeException('carrier exploded'));

        $this->storeEmulation->expects(self::once())->method('stopEnvironmentEmulation');

        $this->management->get($this->updateContainer);
    }

    /**
     * A quote states no store view at all when its store id is missing, and store 0 is the admin
     * store, which no quote belongs to. Emulating it would rate the order against the admin scope.
     */
    public function testDoesNotEmulateWhenTheQuoteStatesNoStore(): void
    {
        $this->quoteStoreId = 0;

        $this->storeEmulation->expects(self::never())->method('startEnvironmentEmulation');
        $this->storeEmulation->expects(self::never())->method('stopEnvironmentEmulation');

        $this->management->get($this->updateContainer);
    }

    /**
     * Nothing to emulate when the request already runs in the quote's store view, and starting one
     * anyway would cost a design and translation reload on every callback.
     */
    public function testDoesNotEmulateWhenTheRequestAlreadyRunsInTheQuoteStore(): void
    {
        $this->currentStoreId = self::QUOTE_STORE_ID;

        $this->storeEmulation->expects(self::never())->method('startEnvironmentEmulation');
        $this->storeEmulation->expects(self::never())->method('stopEnvironmentEmulation');

        $this->management->get($this->updateContainer);
    }

    /**
     * Magento allows a single level of emulation and refuses a nested one silently, leaving the
     * current store where the outer emulation put it. Stopping that refused one would end the
     * emulation the caller is still inside, so a start that did not take effect gets no stop.
     */
    public function testDoesNotStopAnEmulationItDidNotStart(): void
    {
        $this->storeEmulation->method('startEnvironmentEmulation')->willReturnCallback(static function (): void {
        });

        $this->storeEmulation->expects(self::never())->method('stopEnvironmentEmulation');

        $this->management->get($this->updateContainer);
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
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
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
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
    private LinkRepositoryInterface&MockObject $linkRepository;
    private CartRepositoryInterface&MockObject $quoteRepository;
    private ValidateOrderBuilder&MockObject $validateOrderBuilder;
    private QliroOrder $management;

    /**
     * What the link answers for its Qliro order id, so a test can hand over one that has none.
     */
    private ?int $linkQliroOrderId = self::QLIRO_ORDER_ID;

    /**
     * Whether the lookup by quote finds a link at all.
     */
    private bool $linkLookupFails = false;

    /**
     * What the link answers for the Magento order it produced, so a test can hand over a quote
     * that has already become one.
     */
    private ?int $linkMagentoOrderId = null;

    protected function setUp(): void
    {
        $this->quoteManagement = $this->createMock(QuoteManagement::class);
        $this->quoteFromOrderConverter = $this->createMock(QuoteFromOrderConverter::class);
        $this->merchantApi = $this->createMock(MerchantInterface::class);

        $link = $this->createMock(LinkInterface::class);
        // Read at call time so a test can say the quote has no Qliro order yet
        $link->method('getQliroOrderId')->willReturnCallback(function () {
            return $this->linkQliroOrderId;
        });
        $link->method('getQuoteId')->willReturn(282629);
        $link->method('getOrderId')->willReturnCallback(function () {
            return $this->linkMagentoOrderId;
        });

        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $this->linkRepository->method('getByQliroOrderId')->willReturn($link);
        $this->linkRepository->method('getByQuoteId')->willReturnCallback(function () use ($link) {
            if ($this->linkLookupFails) {
                throw new NoSuchEntityException(__('No such entity.'));
            }

            return $link;
        });

        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->quoteRepository->method('get')
            ->willReturn($this->createMock(\Magento\Quote\Model\Quote::class));

        $this->validateOrderBuilder = $this->createMock(ValidateOrderBuilder::class);
        $this->validateOrderBuilder->method('setQuote')->willReturnSelf();
        $this->validateOrderBuilder->method('setValidationRequest')->willReturnSelf();

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
            $this->validateOrderBuilder,
            $this->createMock(QuoteFromValidateConverter::class),
            $this->quoteFromOrderConverter,
            $this->linkRepository,
            $this->quoteRepository,
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
     * The customer event is the first moment the store can learn where the buyer lives, and
     * until it does the checkout has no delivery to show. Qliro withholds the address from the
     * event itself, so the read that follows is what makes the difference, and it has to happen
     * here rather than wait for the browser to ask for it (PLIN-376).
     */
    public function testReadsTheOrderBackWhenTheQuoteHasNoDestination(): void
    {
        $this->management->setQuote($this->quoteWithAddress(null, null));

        $this->merchantApi->expects(self::once())->method('getOrder')->with(self::QLIRO_ORDER_ID);

        $this->management->refreshAfterCustomerEvent();
    }

    /**
     * What the country selector leaves behind is enough to name a country and not enough to rate.
     */
    public function testReadsTheOrderBackWhenOnlyTheCountryIsKnown(): void
    {
        $this->management->setQuote($this->quoteWithAddress(null, 'SE'));

        $this->merchantApi->expects(self::once())->method('getOrder');

        $this->management->refreshAfterCustomerEvent();
    }

    public function testLeavesTheOrderAloneWhenTheQuoteCanAlreadyBeRated(): void
    {
        $this->management->setQuote($this->quoteWithAddress('11329', 'SE'));

        $this->merchantApi->expects(self::never())->method('getOrder');

        $this->management->refreshAfterCustomerEvent();
    }

    public function testLeavesAVirtualQuoteAlone(): void
    {
        $this->management->setQuote($this->quoteWithAddress(null, null, true));

        $this->merchantApi->expects(self::never())->method('getOrder');

        $this->management->refreshAfterCustomerEvent();
    }

    /**
     * get() reaches getLinkFromQuote(), which creates a Qliro order for a quote that has none.
     * A customer event must not be what creates one.
     */
    public function testDoesNotReachTheOrderPathWhenThereIsNoOrderYet(): void
    {
        $this->linkQliroOrderId = null;

        $this->management->setQuote($this->quoteWithAddress(null, null));

        $this->merchantApi->expects(self::never())->method('getOrder');

        $this->management->refreshAfterCustomerEvent();
    }

    /**
     * The point of not calling get(): getLinkFromQuote() rates the whole quote to hash the
     * update payload whether anything changed or not, and this runs on the buyer's critical
     * path, on a merchant whose carrier calls an external service.
     */
    public function testReadsTheOrderBackWithoutRatingTheQuoteFirst(): void
    {
        $this->management->setQuote($this->quoteWithAddress(null, null));

        $this->quoteManagement->expects(self::never())->method('getLinkFromQuote');

        $this->management->refreshAfterCustomerEvent();
    }

    /**
     * The push costs a rating and a call to Qliro, so it is only worth making when the order
     * taught the quote something the checkout does not have yet.
     */
    public function testPushesTheUpdateWhenTheOrderTaughtTheQuoteTheAddress(): void
    {
        $this->quoteFromOrderConverter->method('convert')->willReturn(true);

        $this->management->setQuote($this->quoteWithAddress(null, null));

        $this->quoteManagement->expects(self::once())->method('update')->with(self::QLIRO_ORDER_ID);

        $this->management->refreshAfterCustomerEvent();
    }

    public function testPushesNothingWhenTheOrderCarriedNoAddressEither(): void
    {
        $this->quoteFromOrderConverter->method('convert')->willReturn(false);

        $this->management->setQuote($this->quoteWithAddress(null, null));

        $this->quoteManagement->expects(self::never())->method('update');

        $this->management->refreshAfterCustomerEvent();
    }

    /**
     * A quote that has already become a Magento order is nobody's to change from a customer
     * event, least of all while the placement is still running.
     */
    public function testLeavesAQuoteThatHasAlreadyBecomeAnOrderAlone(): void
    {
        $this->linkMagentoOrderId = 900016;

        $this->management->setQuote($this->quoteWithAddress(null, null));

        $this->merchantApi->expects(self::never())->method('getOrder');

        $this->management->refreshAfterCustomerEvent();
    }

    /**
     * A quote Qliro has never heard of has no link at all, and the lookup says so by throwing.
     */
    public function testSurvivesAQuoteWithNoLink(): void
    {
        $this->linkLookupFails = true;

        $this->management->setQuote($this->quoteWithAddress(null, null));

        $this->merchantApi->expects(self::never())->method('getOrder');

        $this->management->refreshAfterCustomerEvent();
    }

    /**
     * The customer payload is already on the quote and the cart refresh still follows, so a
     * failed read may not become a failed customer update.
     */
    public function testAFailedReadIsSwallowed(): void
    {
        $this->merchantApi->method('getOrder')->willThrowException(new \RuntimeException('Qliro is down'));

        $this->management->setQuote($this->quoteWithAddress(null, null));

        $this->management->refreshAfterCustomerEvent();

        $this->expectNotToPerformAssertions();
    }

    /**
     * @param string|null $postcode
     * @param string|null $countryId
     * @param bool $isVirtual
     * @return \Magento\Quote\Model\Quote&MockObject
     */
    private function quoteWithAddress(?string $postcode, ?string $countryId, bool $isVirtual = false)
    {
        $address = $this->createMock(\Magento\Quote\Model\Quote\Address::class);
        $address->method('getPostcode')->willReturn($postcode);
        $address->method('getCountryId')->willReturn($countryId);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getId')->willReturn(282629);
        $quote->method('isVirtual')->willReturn($isVirtual);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
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

    /**
     * The mark is what closes the quote to further changes, and it belongs to the validation that
     * approved the order, not to the payment the buyer has only begun.
     */
    public function testMarksTheLinkValidatedWhenTheOrderIsApproved(): void
    {
        $response = $this->createMock(ValidateOrderResponseInterface::class);
        $response->method('getDeclineReason')->willReturn(null);
        $this->validateOrderBuilder->method('create')->willReturn($response);

        $this->linkRepository->expects(self::once())->method('markValidated')->with(282629);

        self::assertSame($response, $this->management->validate($this->buildValidationRequest()));
    }

    /**
     * A declined order leaves the quote open: the buyer is still in the checkout and the delivery
     * they pick next has to reach it.
     */
    public function testDoesNotMarkTheLinkValidatedWhenTheOrderIsDeclined(): void
    {
        $response = $this->createMock(ValidateOrderResponseInterface::class);
        $response->method('getDeclineReason')
            ->willReturn(ValidateOrderResponseInterface::REASON_SHIPPING);
        $this->validateOrderBuilder->method('create')->willReturn($response);

        $this->linkRepository->expects(self::never())->method('markValidated');

        self::assertSame($response, $this->management->validate($this->buildValidationRequest()));
    }

    /**
     * A failure to write the mark must not turn an approved order into a declined one.
     */
    public function testApprovesEvenWhenTheMarkCannotBeWritten(): void
    {
        $response = $this->createMock(ValidateOrderResponseInterface::class);
        $response->method('getDeclineReason')->willReturn(null);
        $this->validateOrderBuilder->method('create')->willReturn($response);

        $this->linkRepository->method('markValidated')
            ->willThrowException(new \RuntimeException('the row was gone'));

        self::assertSame($response, $this->management->validate($this->buildValidationRequest()));
    }

    private function buildValidationRequest(): ValidateOrderNotificationInterface&MockObject
    {
        $request = $this->createMock(ValidateOrderNotificationInterface::class);
        $request->method('getOrderId')->willReturn(self::QLIRO_ORDER_ID);

        return $request;
    }
}

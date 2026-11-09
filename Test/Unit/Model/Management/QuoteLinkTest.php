<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\QuoteRepository\LoadHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderUpdateRequestInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Helper\Data as Helper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\NoOrderLinesException;
use Qliro\QliroOne\Model\Exception\UnsupportedQuoteException;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\CountrySelect;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter;
use Qliro\QliroOne\Service\General\LinkService;

/**
 * A Qliro order is created only for a request that carries order lines
 *
 * @see \Qliro\QliroOne\Model\Management\Quote::getLinkFromQuote()
 */
class QuoteLinkTest extends TestCase
{
    private const QUOTE_ID = 9105;
    private const QLIRO_ORDER_ID = 276400077;

    private MerchantInterface&MockObject $merchantApi;
    private LinkRepositoryInterface&MockObject $linkRepository;
    private LogManager&MockObject $logManager;
    private QliroOrderCreateRequestInterface&MockObject $request;
    private QliroOrderUpdateRequestInterface&MockObject $updateRequest;
    private LinkInterface&MockObject $link;
    private Quote&MockObject $quote;
    private QuoteManagement $management;

    protected function setUp(): void
    {
        $this->merchantApi = $this->createMock(MerchantInterface::class);
        $this->link = $this->createMock(LinkInterface::class);
        $this->link->method('getId')->willReturn(3);
        $this->link->method('getQuoteId')->willReturn(self::QUOTE_ID);
        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $this->linkRepository->method('getByQuoteId')->willThrowException(new NoSuchEntityException());
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link);
        $this->logManager = $this->createMock(LogManager::class);

        $linkFactory = $this->createMock(LinkInterfaceFactory::class);
        $linkFactory->method('create')->willReturn($this->createMock(LinkInterface::class));

        $this->request = $this->createMock(QliroOrderCreateRequestInterface::class);
        $createRequestBuilder = $this->createMock(CreateRequestBuilder::class);
        $createRequestBuilder->method('setQuote')->willReturnSelf();
        $createRequestBuilder->method('create')->willReturn($this->request);

        $this->updateRequest = $this->createMock(QliroOrderUpdateRequestInterface::class);
        $updateRequestBuilder = $this->createMock(UpdateRequestBuilder::class);
        $updateRequestBuilder->method('setQuote')->willReturnSelf();
        $updateRequestBuilder->method('create')->willReturn($this->updateRequest);
        $containerMapper = $this->createMock(ContainerMapper::class);
        $containerMapper->method('toArray')->willReturn([]);

        $linkService = $this->createMock(LinkService::class);
        $linkService->method('generateOrderReference')->willReturn('ref9105');

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getEntityId')->willReturn(self::QUOTE_ID);
        $this->quote->method('getId')->willReturn(self::QUOTE_ID);
        $this->quote->method('getIsActive')->willReturn(true);
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('get')->willReturn($this->quote);

        $this->management = new QuoteManagement(
            $this->createMock(Config::class),
            $linkService,
            $this->merchantApi,
            $createRequestBuilder,
            $updateRequestBuilder,
            $this->createMock(CustomerConverter::class),
            $linkFactory,
            $this->linkRepository,
            $quoteRepository,
            $containerMapper,
            $this->logManager,
            $this->createMock(Json::class),
            $this->createMock(Fee::class),
            $this->createMock(Helper::class),
            $this->createMock(ManagerInterface::class),
            $this->createMock(LoadHandler::class),
            $this->createMock(CountrySelect::class)
        );
        $this->management->setQuote($this->quote);
    }

    public function testAQuoteWithoutItemsCreatesNoOrder(): void
    {
        $this->request->method('getOrderItems')->willReturn([]);
        $this->quote->method('getAllItems')->willReturn([]);
        $this->expectRefusal('create', 'the quote has no items', []);

        $this->management->getLinkFromQuote();
    }

    public function testItemsThatBecameNoLineAreLogged(): void
    {
        $item = $this->createMock(Item::class);
        $item->method('getId')->willReturn(51);
        $item->method('getSku')->willReturn('GIFT-1');
        $item->method('getProductType')->willReturn('giftcard');
        $item->method('getQty')->willReturn(1);
        $this->request->method('getOrderItems')->willReturn(null);
        $this->quote->method('getAllItems')->willReturn([$item]);
        $this->expectRefusal('create', 'none of the quote items became an order line', [[
            'item_id' => 51,
            'sku' => 'GIFT-1',
            'product_type' => 'giftcard',
            'qty' => 1,
            'parent_item_id' => null,
        ]], UnsupportedQuoteException::class);

        try {
            $this->management->getLinkFromQuote();
        } catch (UnsupportedQuoteException $exception) {
            // Shown in place of the widget, not a redirect to the cart, so another method can be chosen
            self::assertNotInstanceOf(NoOrderLinesException::class, $exception);

            throw $exception;
        }
    }

    public function testADiscountLineAloneCreatesNoOrder(): void
    {
        $this->request->method('getOrderItems')->willReturn([$this->line(QliroOrderItemInterface::TYPE_DISCOUNT)]);
        $this->quote->method('getAllItems')->willReturn([]);
        $this->expectRefusal('create', 'the quote has no items', []);

        $this->management->getLinkFromQuote();
    }

    /**
     * @dataProvider productTypes
     */
    public function testARequestWithAProductLineCreatesTheOrder(string $type): void
    {
        $this->request->method('getOrderItems')->willReturn([
            $this->line(QliroOrderItemInterface::TYPE_SHIPPING),
            $this->line($type),
        ]);
        $this->merchantApi->expects(self::once())->method('createOrder')->willReturn(4242);
        $this->linkRepository->expects(self::once())->method('save');

        $this->management->getLinkFromQuote();
    }

    public static function productTypes(): array
    {
        return [
            'product' => [QliroOrderItemInterface::TYPE_PRODUCT],
            'bundle' => [QliroOrderItemInterface::TYPE_BUNDLE],
        ];
    }

    /**
     * An open checkout whose cart was emptied: the update is not sent and the link stays as it is
     */
    public function testAnEmptiedCartSendsNoUpdate(): void
    {
        $this->updateRequest->method('getOrderItems')->willReturn([$this->line(QliroOrderItemInterface::TYPE_SHIPPING)]);
        $this->quote->method('getAllItems')->willReturn([]);
        $this->merchantApi->expects(self::never())->method('updateOrder');
        $this->link->expects(self::never())->method('setIsActive');
        $this->linkRepository->expects(self::never())->method('save');
        $this->logManager->expects(self::never())->method('critical');
        $this->logManager->expects(self::once())->method('warning')->with(
            'Qliro order update not sent, the request has no product lines',
            ['extra' => ['quote_id' => self::QUOTE_ID, 'reason' => 'the quote has no items', 'items' => []]]
        );

        $this->expectException(NoOrderLinesException::class);
        $this->management->update(self::QLIRO_ORDER_ID);
    }

    public function testItemsWithoutLinesSendNoUpdateAndKeepTheLink(): void
    {
        $item = $this->createMock(Item::class);
        $item->method('getId')->willReturn(52);
        $this->updateRequest->method('getOrderItems')->willReturn([]);
        $this->quote->method('getAllItems')->willReturn([$item]);
        $this->merchantApi->expects(self::never())->method('updateOrder');
        $this->link->expects(self::never())->method('setIsActive');
        $this->linkRepository->expects(self::never())->method('save');
        $this->logManager->expects(self::never())->method('critical');

        try {
            $this->management->update(self::QLIRO_ORDER_ID);
            self::fail('The update was not refused');
        } catch (UnsupportedQuoteException $exception) {
            self::assertNotInstanceOf(NoOrderLinesException::class, $exception);
        }
    }

    public function testAnUpdateWithAProductLineIsSent(): void
    {
        $this->updateRequest->method('getOrderItems')->willReturn([$this->line(QliroOrderItemInterface::TYPE_PRODUCT)]);
        $this->merchantApi->expects(self::once())->method('updateOrder')->with(self::QLIRO_ORDER_ID);

        $this->management->update(self::QLIRO_ORDER_ID, true);
    }

    private function line(string $type): QliroOrderItemInterface&MockObject
    {
        $line = $this->createMock(QliroOrderItemInterface::class);
        $line->method('getType')->willReturn($type);

        return $line;
    }

    private function expectRefusal(
        string $call,
        string $reason,
        array $items,
        string $exception = NoOrderLinesException::class
    ): void {
        $this->merchantApi->expects(self::never())->method('createOrder');
        $this->linkRepository->expects(self::never())->method('save');
        $this->logManager->expects(self::once())->method('warning')->with(
            'Qliro order ' . $call . ' not sent, the request has no product lines',
            ['extra' => ['quote_id' => self::QUOTE_ID, 'reason' => $reason, 'items' => $items]]
        );
        $this->expectException($exception);
    }
}

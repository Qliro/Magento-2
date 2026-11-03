<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Carrier\Ingrid;
use Qliro\QliroOne\Model\Carrier\Unifaun;
use Qliro\QliroOne\Model\Config;

/**
 * The titles the Unifaun and Ingrid rates take from the shipping description Qliro left on the cart
 *
 * @see \Qliro\QliroOne\Model\Carrier\Unifaun::collectRates
 * @see \Qliro\QliroOne\Model\Carrier\Ingrid::collectRates
 */
class QliroCarrierTitleTest extends TestCase
{
    public static function carriers(): array
    {
        return [
            'Unifaun' => [Unifaun::class, 'Unifaun'],
            'Ingrid' => [Ingrid::class, 'Ingrid'],
        ];
    }

    #[DataProvider('carriers')]
    public function testSplitsTheDescriptionIntoCarrierAndMethod(string $class, string $provider): void
    {
        $method = $this->collect($class, [$this->item()], $provider . ' - PostNord - Ombud (12)');

        self::assertSame($provider, $method->getCarrierTitle());
        self::assertSame('PostNord - Ombud (12)', $method->getMethodTitle());
    }

    /**
     * The guard and the split read the same separator, so a description without it keeps the configured titles
     */
    #[DataProvider('carriers')]
    public function testKeepsTheConfiguredTitlesWithoutTheSeparator(string $class, string $provider): void
    {
        $method = $this->collect($class, [$this->item()], $provider . ' -PostNord');

        self::assertSame('Configured title', $method->getCarrierTitle());
        self::assertSame('Configured name', $method->getMethodTitle());
    }

    /**
     * A carrier title with more in front of the provider name, as a store may configure it
     */
    #[DataProvider('carriers')]
    public function testReadsADescriptionWithMoreInFrontOfTheName(string $class, string $provider): void
    {
        $method = $this->collect($class, [$this->item()], 'Qliro ' . $provider . ' - Budbee');

        self::assertSame('Qliro ' . $provider, $method->getCarrierTitle());
        self::assertSame('Budbee', $method->getMethodTitle());
    }

    /**
     * The provider is the carrier whatever comes in front of it
     */
    #[DataProvider('carriers')]
    public function testKeepsTheProviderInTheCarrierTitle(string $class, string $provider): void
    {
        $method = $this->collect($class, [$this->item()], 'Budget - ' . $provider . ' - PostNord');

        self::assertSame('Budget - ' . $provider, $method->getCarrierTitle());
        self::assertSame('PostNord', $method->getMethodTitle());
    }

    #[DataProvider('carriers')]
    public function testKeepsTheConfiguredMethodTitleWhenNothingFollowsTheSeparator(string $class, string $provider): void
    {
        $method = $this->collect($class, [$this->item()], $provider . ' - ');

        self::assertSame($provider, $method->getCarrierTitle());
        self::assertSame('Configured name', $method->getMethodTitle());
    }

    /**
     * The request items are not always keyed from 0
     */
    #[DataProvider('carriers')]
    public function testReadsTheFirstItemWhateverItsKey(string $class, string $provider): void
    {
        $method = $this->collect($class, [7 => $this->item()], $provider . ' - Budbee');

        self::assertSame('Budbee', $method->getMethodTitle());
    }

    /**
     * An item that carries the quote id without the quote has the quote loaded by the id
     */
    #[DataProvider('carriers')]
    public function testLoadsTheQuoteOfAnItemWithoutOne(string $class, string $provider): void
    {
        $item = $this->item();
        $item->setData('quote_id', 5);

        $method = $this->collect($class, [$item], $provider . ' - Budbee');

        self::assertSame('Budbee', $method->getMethodTitle());
    }

    #[DataProvider('carriers')]
    public function testKeepsTheConfiguredTitlesWithoutItems(string $class, string $provider): void
    {
        foreach ([[], null] as $items) {
            $method = $this->collect($class, $items, null);

            self::assertSame('Configured title', $method->getCarrierTitle());
            self::assertSame('Configured name', $method->getMethodTitle());
        }
    }

    private function collect(string $class, ?array $items, ?string $description): Method
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => match (substr($path, strrpos($path, '/') + 1)) {
                'title' => 'Configured title',
                'name' => 'Configured name',
                'price' => '49',
                default => null,
            }
        );

        $result = new Result($this->createMock(\Magento\Store\Model\StoreManagerInterface::class));
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturn($result);

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setPrice'])
            ->getMock();
        $methodFactory = $this->createMock(MethodFactory::class);
        $methodFactory->method('create')->willReturn($method);

        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $address->setData('shipping_description', $description);
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(5);
        $quote->method('getShippingAddress')->willReturn($address);
        foreach ($items ?? [] as $item) {
            if ($item->getQuoteId() === null) {
                $item->setQuote($quote);
            }
        }
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('get')->with(5)->willReturn($quote);

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQuoteId')->willThrowException(new NoSuchEntityException());

        $qliroConfig = $this->createMock(Config::class);
        $qliroConfig->method('isUnifaunEnabled')->willReturn(true);
        $qliroConfig->method('isIngridEnabled')->willReturn(true);

        $carrier = new $class(
            $scopeConfig,
            $this->createMock(ErrorFactory::class),
            $this->createMock(LoggerInterface::class),
            $resultFactory,
            $methodFactory,
            $linkRepository,
            $quoteRepository,
            $qliroConfig
        );

        $request = new RateRequest(['all_items' => $items]);

        self::assertSame($result, $carrier->collectRates($request));

        return $method;
    }

    private function item(): QuoteItem
    {
        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        return $item;
    }
}

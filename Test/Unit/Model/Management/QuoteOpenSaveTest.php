<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Model\QuoteRepository\LoadHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Helper\Data as Helper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\CountrySelect;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter;
use Qliro\QliroOne\Service\General\LinkService;

/**
 * @see \Qliro\QliroOne\Model\Management\Quote::recalculateAndSaveOpenQuote
 */
class QuoteOpenSaveTest extends TestCase
{
    private Address&MockObject $billing;
    private Address&MockObject $shipping;
    private Quote&MockObject $quote;
    private CartRepositoryInterface&MockObject $quoteRepository;
    /** @var array{billing: mixed, shipping: mixed}|null The flags as the repository save saw them */
    private ?array $seenBySave = null;
    private ?array $seenByNestedSave = null;
    private QuoteManagement $management;

    protected function setUp(): void
    {
        $this->billing = $this->address();
        $this->shipping = $this->address();

        $quote = $this->createMock(Quote::class);
        $this->quote = $quote;
        $quote->method('getBillingAddress')->willReturn($this->billing);
        $quote->method('getShippingAddress')->willReturn($this->shipping);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getCustomer')->willReturn($this->createMock(CustomerInterface::class));
        $quote->method('getAllItems')->willReturn([]);
        $quote->method('getPayment')->willReturn($this->createMock(Payment::class));

        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->quoteRepository->method('save')->willReturnCallback(function () {
            $this->seenBySave = [
                'billing' => $this->billing->getShouldIgnoreValidation(),
                'shipping' => $this->shipping->getShouldIgnoreValidation(),
            ];
        });

        $this->management = new QuoteManagement(
            $this->createMock(Config::class),
            $this->createMock(LinkService::class),
            $this->createMock(MerchantInterface::class),
            $this->createMock(CreateRequestBuilder::class),
            $this->createMock(UpdateRequestBuilder::class),
            $this->createMock(CustomerConverter::class),
            $this->createMock(LinkInterfaceFactory::class),
            $this->createMock(LinkRepositoryInterface::class),
            $this->quoteRepository,
            $this->createMock(ContainerMapper::class),
            $this->createMock(LogManager::class),
            $this->createMock(Json::class),
            $this->createMock(Fee::class),
            $this->createMock(Helper::class),
            $this->createMock(ManagerInterface::class),
            $this->createMock(LoadHandler::class),
            $this->createMock(CountrySelect::class)
        );
        $this->management->setQuote($quote);
    }

    public function testAnOpenCheckoutSavesANamelessCompanyAddress(): void
    {
        $this->billing->addData(['company' => 'Test Company AS', 'city' => 'Oslo']);
        $this->shipping->addData(['company' => 'Test Company AS', 'city' => 'Oslo']);

        $this->management->recalculateAndSaveOpenQuote();

        self::assertSame(['billing' => true, 'shipping' => true], $this->seenBySave);
        self::assertNull($this->billing->getShouldIgnoreValidation());
        self::assertNull($this->shipping->getShouldIgnoreValidation());
    }

    public function testAMissingLastNameIsEnough(): void
    {
        $this->shipping->addData(['firstname' => 'Test', 'lastname' => ' ']);
        $this->billing->addData(['firstname' => 'Test', 'lastname' => 'Person']);

        $this->management->recalculateAndSaveOpenQuote();

        self::assertSame(['billing' => null, 'shipping' => true], $this->seenBySave);
    }

    public function testAnAddressWithANameIsCheckedAsUsual(): void
    {
        foreach ([$this->billing, $this->shipping] as $address) {
            $address->addData(['firstname' => 'Test', 'lastname' => 'Person']);
        }

        $this->management->recalculateAndSaveOpenQuote();

        self::assertSame(['billing' => null, 'shipping' => null], $this->seenBySave);
    }

    /** Placing the order saves through the plain method and keeps Magento's full check */
    public function testThePlainSaveKeepsTheCheck(): void
    {
        $this->management->recalculateAndSaveQuote();

        self::assertSame(['billing' => null, 'shipping' => null], $this->seenBySave);
    }

    public function testTheCheckComesBackWhenTheSaveFails(): void
    {
        $this->replaceTheRepository();
        $this->quoteRepository->method('save')->willThrowException(new \RuntimeException('refused'));

        try {
            $this->management->recalculateAndSaveOpenQuote();
            self::fail('The save failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('refused', $e->getMessage());
        }

        self::assertNull($this->shipping->getShouldIgnoreValidation());

        // and the next plain save is not left without the check
        $this->replaceTheRepository();
        $this->quoteRepository->method('save')->willReturnCallback(function () {
            $this->seenBySave = ['billing' => null, 'shipping' => $this->shipping->getShouldIgnoreValidation()];
        });
        $this->management->recalculateAndSaveQuote();

        self::assertNull($this->seenBySave['shipping']);
    }

    /** The save validates the shipping assignment's address, which can be another object */
    public function testTheShippingAssignmentAddressIsCoveredToo(): void
    {
        $assigned = $this->address();
        $assigned->addData(['company' => 'Test Company AS', 'city' => 'Oslo']);
        foreach ([$this->billing, $this->shipping] as $address) {
            $address->addData(['firstname' => 'Test', 'lastname' => 'Person']);
        }

        $shipping = $this->createMock(ShippingInterface::class);
        $shipping->method('getAddress')->willReturn($assigned);
        $assignment = $this->createMock(ShippingAssignmentInterface::class);
        $assignment->method('getShipping')->willReturn($shipping);
        // CartExtensionInterface is generated by Magento and missing here
        $extension = new class ([$assignment]) {
            public function __construct(private array $assignments)
            {
            }

            public function getShippingAssignments(): array
            {
                return $this->assignments;
            }
        };
        $this->quote->method('getExtensionAttributes')->willReturn($extension);

        $seen = null;
        $this->replaceTheRepository();
        $this->quoteRepository->method('save')->willReturnCallback(function () use ($assigned, &$seen) {
            $seen = $assigned->getShouldIgnoreValidation();
        });

        $this->management->recalculateAndSaveOpenQuote();

        self::assertTrue($seen);
        self::assertNull($assigned->getShouldIgnoreValidation());
    }

    /** A plain save started by an observer during the open one keeps the check */
    public function testASaveNestedInTheOpenOneKeepsTheCheck(): void
    {
        $this->shipping->addData(['company' => 'Test Company AS', 'city' => 'Oslo']);
        $nested = false;
        $this->quote->method('collectTotals')->willReturnCallback(function () use (&$nested) {
            if (!$nested) {
                $nested = true;
                $this->management->recalculateAndSaveQuote();
                $this->seenByNestedSave = $this->seenBySave;
            }

            return $this->quote;
        });

        $this->management->recalculateAndSaveOpenQuote();

        self::assertNull($this->seenByNestedSave['shipping']);
        self::assertTrue($this->seenBySave['shipping']);
    }

    public function testAFlagSetBySomeoneElseIsLeftAlone(): void
    {
        $this->shipping->setShouldIgnoreValidation(true);

        $this->management->recalculateAndSaveOpenQuote();

        self::assertTrue($this->shipping->getShouldIgnoreValidation());
    }

    private function replaceTheRepository(): void
    {
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        (new \ReflectionProperty(QuoteManagement::class, 'quoteRepository'))
            ->setValue($this->management, $this->quoteRepository);
    }

    private function address(): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collectShippingRates', 'save'])
            ->getMock();
        $address->method('save')->willReturnSelf();
        $address->method('collectShippingRates')->willReturnSelf();

        return $address;
    }
}

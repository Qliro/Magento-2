<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Order;

use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Order\OrganizationNumber;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter;
use Qliro\QliroOne\Model\QliroOrder\Customer;

/**
 * @see \Qliro\QliroOne\Model\Order\OrganizationNumber
 *
 * The number belongs on the order and not on the quote: `vat_id` on a quote address is what
 * Magento validates against VIES when automatic customer group assignment is on, and an
 * organisation number is not a VAT number, so the buyer would be moved into the group a store
 * keeps for an invalid one, with the tax class that group carries.
 */
class OrganizationNumberTest extends TestCase
{
    /** @var OrderAddressInterface[] */
    private array $saved = [];

    private OrganizationNumber $organizationNumber;

    protected function setUp(): void
    {
        $this->saved = [];

        $repository = $this->createMock(OrderAddressRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(
            function (OrderAddressInterface $address) {
                $this->saved[] = $address;

                return $address;
            }
        );

        $this->organizationNumber = new OrganizationNumber(
            new AddressConverter($this->createMock(DirectoryHelper::class)),
            $repository,
            $this->createMock(LogManager::class)
        );
    }

    /**
     * The number Qliro glued to the company name reaches the invoice and the shipping label
     * through the order address, which is the only place it is written.
     */
    public function testWritesTheNumberToBothOrderAddresses(): void
    {
        $billing = $this->orderAddress();
        $shipping = $this->orderAddress();

        $this->organizationNumber->apply(
            $this->order($billing, $shipping),
            $this->qliroOrder('964969124 Gloppen Kommune', '964969124')
        );

        self::assertSame('964969124', $billing->getVatId());
        self::assertSame('964969124', $shipping->getVatId());
        self::assertCount(2, $this->saved);
    }

    /**
     * A virtual order has no shipping address, and nothing else is expected to answer for it.
     */
    public function testWritesTheNumberWhenTheOrderHasNoShippingAddress(): void
    {
        $billing = $this->orderAddress();

        $this->organizationNumber->apply(
            $this->order($billing, null),
            $this->qliroOrder('964969124 Gloppen Kommune', '964969124')
        );

        self::assertSame('964969124', $billing->getVatId());
        self::assertCount(1, $this->saved);
    }

    /**
     * For a buyer who is not a company the same Qliro field carries their own identity number,
     * which has no business on an order address at all.
     */
    public function testWritesNothingForABuyerWithoutACompany(): void
    {
        $billing = $this->orderAddress();

        $this->organizationNumber->apply(
            $this->order($billing, null),
            $this->qliroOrder(null, '19800101-1234')
        );

        self::assertNull($billing->getVatId());
        self::assertSame([], $this->saved);
    }

    /**
     * A number already on the address was put there by the store, its own checkout form or an
     * address book, and it is not ours to replace.
     */
    public function testKeepsANumberTheOrderAlreadyCarries(): void
    {
        $billing = $this->orderAddress('SE556036079301');

        $this->organizationNumber->apply(
            $this->order($billing, null),
            $this->qliroOrder('964969124 Gloppen Kommune', '964969124')
        );

        self::assertSame('SE556036079301', $billing->getVatId());
        self::assertSame([], $this->saved);
    }

    /**
     * The order is placed and paid for by the time this runs, so a failure to save a line on an
     * address is logged and never thrown at the buyer.
     */
    public function testAFailedSaveDoesNotReachTheCaller(): void
    {
        $repository = $this->createMock(OrderAddressRepositoryInterface::class);
        $repository->method('save')->willThrowException(new \RuntimeException('table is gone'));

        $logManager = $this->createMock(LogManager::class);
        $logManager->expects(self::once())->method('warning');

        $organizationNumber = new OrganizationNumber(new AddressConverter($this->createMock(DirectoryHelper::class)), $repository, $logManager);

        $organizationNumber->apply(
            $this->order($this->orderAddress(), null),
            $this->qliroOrder('964969124 Gloppen Kommune', '964969124')
        );
    }

    private function order(?Address $billing, ?Address $shipping): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getBillingAddress')->willReturn($billing);
        $order->method('getShippingAddress')->willReturn($shipping);
        $order->method('getEntityId')->willReturn(11);

        return $order;
    }

    private function orderAddress(?string $vatId = null): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getVatId', 'setVatId', 'getEntityId'])
            ->getMock();

        // By reference: an arrow function would capture the value as it is now, so the read
        // after the write would still answer null
        $stored = $vatId;
        $address->method('getVatId')->willReturnCallback(
            function () use (&$stored) {
                return $stored;
            }
        );
        $address->method('setVatId')->willReturnCallback(
            function ($value) use (&$stored, $address) {
                $stored = $value;

                return $address;
            }
        );
        $address->method('getEntityId')->willReturn(21);

        return $address;
    }

    private function qliroOrder(?string $company, ?string $personalNumber): QliroOrderInterface&MockObject
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroAddress->method('getCompanyName')->willReturn($company);

        $customer = $this->createMock(Customer::class);
        $customer->method('getPersonalNumber')->willReturn($personalNumber);
        $customer->method('getVatNumber')->willReturn(null);

        $qliroOrder = $this->createMock(QliroOrderInterface::class);
        $qliroOrder->method('getCustomer')->willReturn($customer);
        $qliroOrder->method('getBillingAddress')->willReturn($qliroAddress);
        $qliroOrder->method('getShippingAddress')->willReturn($qliroAddress);

        return $qliroOrder;
    }
}

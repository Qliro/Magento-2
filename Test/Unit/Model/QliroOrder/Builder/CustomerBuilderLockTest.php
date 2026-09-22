<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Customer\Model\AddressFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Address\Address as QliroAddress;
use Qliro\QliroOne\Model\QliroOrder\Builder\CustomerAddressBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\CustomerBuilder;
use Qliro\QliroOne\Model\QliroOrder\Customer;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\CustomerBuilder::create
 *
 * PLIN-419: in the iframe mode the native checkout collected the address, the email and the phone
 * before Qliro is ever shown, so the iframe presents them and does not let the buyer edit them.
 * Every other mode keeps the flags it had, because Qliro owns the form there.
 */
class CustomerBuilderLockTest extends TestCase
{
    private Quote&MockObject $quote;
    private Address&MockObject $shippingAddress;
    private Config&MockObject $qliroConfig;
    private CustomerBuilder $builder;

    protected function setUp(): void
    {
        $this->shippingAddress = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->shippingAddress->addData([
            'firstname' => 'Alex',
            'lastname' => 'Berg',
            'street' => "Storgatan 1\n",
            'postcode' => '11122',
            'city' => 'Stockholm',
            'country_id' => 'SE',
            'email' => 'alex@example.com',
            'telephone' => '+46700000000',
        ]);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getIsVirtual')->willReturn(false);
        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $this->quote->method('getStoreId')->willReturn(1);

        $customerAddressFactory = $this->createMock(QliroOrderCustomerAddressInterfaceFactory::class);
        $customerAddressFactory->method('create')->willReturnCallback(fn () => new QliroAddress());

        $customerFactory = $this->createMock(QliroOrderCustomerInterfaceFactory::class);
        $customerFactory->method('create')->willReturnCallback(fn () => new Customer());

        $this->qliroConfig = $this->createMock(Config::class);
        $this->qliroConfig->method('getShowAsPaymentMethod')->willReturn(true);

        $this->builder = new CustomerBuilder(
            $customerFactory,
            new CustomerAddressBuilder($customerAddressFactory),
            $this->createMock(AddressFactory::class),
            $this->qliroConfig
        );
    }

    /**
     * The iframe mode locks what the native checkout already collected, with the flag Qliro reads
     * for it: on the customer block, not at the top level of the create request. Measured against
     * the sandbox, the widget offers neither "Ändra" nor the personal number lookup with it, and
     * the same flag at the top level changes nothing.
     */
    public function testTheIframeModeLocksTheCollectedData(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);

        $customer = $this->builder->setQuote($this->quote)->setCustomer(null)->create();

        $this->assertTrue($customer->getLockCustomerInformation());
        $this->assertTrue($customer->getLockCustomerAddress());
        $this->assertTrue($customer->getLockCustomerEmail());
    }

    /**
     * An address with nothing in it is never locked, whatever the mode. A virtual cart takes the
     * billing address, and the native checkout collects that inside the payment step, so at the
     * moment Qliro is chosen it can still be empty. Locking it would leave the buyer with nowhere
     * at all to enter an address.
     */
    public function testAnEmptyAddressIsNotLocked(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);
        $this->shippingAddress->addData(['street' => '', 'postcode' => '', 'city' => '']);

        $customer = $this->builder->setQuote($this->quote)->setCustomer(null)->create();

        $this->assertFalse($customer->getLockCustomerAddress());

        // And the block is not locked either, because that would lock the empty address with it
        $this->assertNull($customer->getLockCustomerInformation());
    }

    /**
     * The per field flag for the phone is sent as false, and it is what a store gets where the
     * block itself cannot be locked. Next to the block lock it decides nothing: measured against
     * the sandbox, a `LockCustomerMobileNumber: false` beside `LockCustomerInformation: true`
     * does not reopen the field. In this mode the phone is the one the native checkout collected.
     */
    public function testThePhoneFlagIsSentAsFalse(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);

        $customer = $this->builder->setQuote($this->quote)->setCustomer(null)->create();

        $this->assertFalse($customer->getLockCustomerMobileNumber());
    }

    /**
     * The redirect mode is the behaviour of every release before this one: Qliro owns the form,
     * so a guest edits everything inside it.
     */
    public function testTheRedirectModeLeavesTheDataEditable(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(false);

        $customer = $this->builder->setQuote($this->quote)->setCustomer(null)->create();

        $this->assertNull($customer->getLockCustomerInformation());
        $this->assertFalse($customer->getLockCustomerAddress());
        $this->assertFalse($customer->getLockCustomerEmail());
        $this->assertFalse($customer->getLockCustomerMobileNumber());
    }
}

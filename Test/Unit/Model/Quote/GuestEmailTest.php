<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Quote;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Framework\Validator\EmailAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Quote\GuestEmail;

/**
 * The email a guest typed before the payment step reaches the Qliro order, and nothing the quote
 * already holds is replaced by what the browser sent.
 *
 * @see \Qliro\QliroOne\Model\Quote\GuestEmail
 */
class GuestEmailTest extends TestCase
{
    private GuestEmail $guestEmail;

    protected function setUp(): void
    {
        $this->guestEmail = new GuestEmail(new EmailAddress());
    }

    public function testAGuestEmailGoesOnTheBillingAddress(): void
    {
        $billing = $this->address(null);
        $billing->expects(self::once())->method('setEmail')->with('buyer@example.com');

        self::assertTrue($this->guestEmail->apply($this->quote(null, $this->address(null), $billing), ' buyer@example.com '));
    }

    /**
     * A Nordic domain spelled in its own letters is still an address
     */
    public function testAnInternationalisedAddressIsAccepted(): void
    {
        $billing = $this->address(null);
        $billing->expects(self::once())->method('setEmail')->with('köpare@kök.se');

        self::assertTrue($this->guestEmail->apply($this->quote(null, $this->address(null), $billing), 'köpare@kök.se'));
    }

    public function testALoggedInBuyerKeepsTheAccountEmail(): void
    {
        $billing = $this->address(null);
        $billing->expects(self::never())->method('setEmail');

        self::assertFalse($this->guestEmail->apply($this->quote(7, $this->address(null), $billing), 'other@example.com'));
    }

    public function testAnEmailAlreadyOnTheQuoteWins(): void
    {
        $billing = $this->address('stored@example.com');
        $billing->expects(self::never())->method('setEmail');

        self::assertFalse($this->guestEmail->apply($this->quote(null, $this->address(null), $billing), 'buyer@example.com'));
    }

    public function testAnEmailOnTheShippingAddressWins(): void
    {
        $billing = $this->address(null);
        $billing->expects(self::never())->method('setEmail');

        self::assertFalse(
            $this->guestEmail->apply($this->quote(null, $this->address('stored@example.com'), $billing), 'buyer@example.com')
        );
    }

    /**
     * @dataProvider notAnEmail
     */
    public function testWhatIsNotAnEmailIsIgnored(string $email): void
    {
        $billing = $this->address(null);
        $billing->expects(self::never())->method('setEmail');

        self::assertFalse($this->guestEmail->apply($this->quote(null, $this->address(null), $billing), $email));
    }

    public static function notAnEmail(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'no domain' => ['buyer@'],
            'markup' => ['<script>@example.com'],
        ];
    }

    private function quote(?int $customerId, Address $shipping, Address $billing): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getBillingAddress'])
            ->addMethods(['getCustomerId'])
            ->getMock();
        $quote->method('getCustomerId')->willReturn($customerId);
        $quote->method('getShippingAddress')->willReturn($shipping);
        $quote->method('getBillingAddress')->willReturn($billing);

        return $quote;
    }

    /**
     * @return Address&MockObject
     */
    private function address(?string $email): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail', 'setEmail'])
            ->getMock();
        $address->method('getEmail')->willReturn($email);

        return $address;
    }
}

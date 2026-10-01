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
 * The email a guest typed before the payment step reaches the Qliro order, nothing the quote
 * already holds is replaced by what the browser sent, and no address is touched.
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

    /**
     * An address set dirty would be saved over the one set-payment-information saves alongside
     */
    public function testAGuestEmailIsKeptOnTheQuoteAndNoAddressIsTouched(): void
    {
        $billing = $this->address(null);
        $billing->expects(self::never())->method('setEmail');
        $shipping = $this->address(null);
        $shipping->expects(self::never())->method('setEmail');
        $quote = $this->quote(null, $shipping, $billing);

        self::assertTrue($this->guestEmail->apply($quote, ' buyer@example.com '));
        self::assertSame('buyer@example.com', $quote->getData(GuestEmail::QUOTE_KEY));
    }

    /**
     * A Nordic domain spelled in its own letters is still an address
     */
    public function testAnInternationalisedAddressIsAccepted(): void
    {
        $quote = $this->quote(null, $this->address(null), $this->address(null));

        self::assertTrue($this->guestEmail->apply($quote, 'köpare@kök.se'));
        self::assertSame('köpare@kök.se', $quote->getData(GuestEmail::QUOTE_KEY));
    }

    public function testALoggedInBuyerKeepsTheAccountEmail(): void
    {
        $billing = $this->address(null);
        $billing->expects(self::never())->method('setEmail');

        $quote = $this->quote(7, $this->address(null), $billing);

        self::assertFalse($this->guestEmail->apply($quote, 'other@example.com'));
        self::assertNull($quote->getData(GuestEmail::QUOTE_KEY));
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

        $quote = $this->quote(null, $this->address(null), $billing);

        self::assertFalse($this->guestEmail->apply($quote, $email));
        self::assertNull($quote->getData(GuestEmail::QUOTE_KEY));
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

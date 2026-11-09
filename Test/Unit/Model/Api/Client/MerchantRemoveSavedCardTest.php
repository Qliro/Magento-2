<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api\Client;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderInterfaceFactory;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Api\Client\Merchant;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * @see \Qliro\QliroOne\Model\Api\Client\Merchant::removeSavedCreditCard
 */
class MerchantRemoveSavedCardTest extends TestCase
{
    private Service&MockObject $service;

    protected function setUp(): void
    {
        $this->service = $this->createMock(Service::class);
    }

    public function testTheCardIsDeletedWithTheStoreCredentials(): void
    {
        $this->service->expects(self::once())->method('delete')
            ->with(
                'checkout/merchantapi/RemoveMerchantSavedCreditCard',
                ['MerchantSavedCreditCardId' => 'card-1'],
                3,
                Config::API_PROFILE_INTERACTIVE
            )
            ->willReturn([]);

        self::assertTrue($this->merchant()->removeSavedCreditCard('card-1', 3));
    }

    /**
     * @dataProvider goneCodes
     */
    public function testACardQliroNoLongerHasIsNotAnError(string $code): void
    {
        $this->service->method('delete')->willThrowException($this->refusal($code));

        self::assertFalse($this->merchant()->removeSavedCreditCard('card-1', 3));
    }

    public static function goneCodes(): array
    {
        return [
            ['MERCHANT_SAVED_CREDIT_CARD_REMOVED'],
            ['MERCHANT_SAVED_CREDIT_CARD_ID_NOT_EXIST'],
        ];
    }

    public function testAnyOtherRefusalThrows(): void
    {
        $this->service->method('delete')
            ->willThrowException($this->refusal('MERCHANT_SAVED_CREDIT_CARD_DOES_NOT_BELONG_TO_MERCHANT'));

        $this->expectException(ClientException::class);

        $this->merchant()->removeSavedCreditCard('card-1', 3);
    }

    public function testATimeoutWithoutAQliroCodeThrows(): void
    {
        $this->service->method('delete')->willThrowException(new TerminalException('cURL error 28'));

        $this->expectException(ClientException::class);

        $this->merchant()->removeSavedCreditCard('card-1', 3);
    }

    private function refusal(string $code): TerminalException
    {
        $exception = new TerminalException('Client error: 400');
        $exception->setQliroError($code, 'refused');

        return $exception;
    }

    private function merchant(): Merchant
    {
        return new Merchant(
            $this->service,
            $this->createMock(Config::class),
            $this->createMock(Json::class),
            $this->createMock(ContainerMapper::class),
            $this->createMock(QliroOrderInterfaceFactory::class),
            $this->createMock(Manager::class)
        );
    }
}

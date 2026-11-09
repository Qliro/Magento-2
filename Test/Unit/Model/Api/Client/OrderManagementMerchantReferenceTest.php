<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api\Client;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\UpdateMerchantReferenceRequest;

/**
 * @see OrderManagement::updateMerchantReference()
 */
class OrderManagementMerchantReferenceTest extends TestCase
{
    private const CODE = 'UPDATE_MERCHANT_REFERENCE_NOT_SUPPORTED';

    private Service&MockObject $service;
    private AdminTransactionResponseInterface&MockObject $empty;

    protected function setUp(): void
    {
        $this->service = $this->createMock(Service::class);
        $this->empty = $this->createMock(AdminTransactionResponseInterface::class);
    }

    public function testTheRefusalIsExpectedForThisReferenceOnly(): void
    {
        $this->service->expects(self::once())->method('post')
            ->with(
                'checkout/adminapi/v2/updatemerchantreference',
                self::anything(),
                1,
                self::anything(),
                self::callback(static fn (callable $expected): bool =>
                    $expected(self::CODE, "Order has reference set to '493792664' already")
                    && !$expected(self::CODE, "Order has reference set to '1' already")
                    && !$expected('INVALID_INPUT', "Order has reference set to '493792664' already"))
            )
            ->willReturn(['PaymentTransactionId' => 1]);

        $this->client()->updateMerchantReference($this->request(), 1);
    }

    /**
     * Read off the quoted reference, so punctuation or casing around it does not matter
     */
    public function testTheQuotedReferenceDecidesNotTheWholeSentence(): void
    {
        $this->refuse('order has reference set to "493792664" already.');

        self::assertSame($this->empty, $this->client()->updateMerchantReference($this->request(), 1));
    }

    public function testAnOrderThatAlreadyCarriesTheReferenceIsASuccess(): void
    {
        $this->refuse("Order has reference set to '493792664' already");

        self::assertSame($this->empty, $this->client()->updateMerchantReference($this->request(), 1));
    }

    public function testAnotherReferenceOnTheOrderIsAFailure(): void
    {
        $this->refuse("Order has reference set to 'X493792664' already");

        self::assertNull($this->client()->updateMerchantReference($this->request(), 1));
    }

    public function testAReferenceTakenByAnotherOrderIsAFailure(): void
    {
        $this->refuse("Order with reference '493792664' already exists");

        self::assertNull($this->client()->updateMerchantReference($this->request(), 1));
    }

    public function testATimeoutIsAFailure(): void
    {
        $this->service->method('post')->willThrowException(new TerminalException('cURL error 28'));

        self::assertNull($this->client()->updateMerchantReference($this->request(), 1));
    }

    private function refuse(string $message): void
    {
        $exception = new TerminalException('Client error: 400 Bad Request');
        $exception->setQliroError(self::CODE, $message);
        $this->service->method('post')->willThrowException($exception);
    }

    private function request(): UpdateMerchantReferenceRequest
    {
        $request = new UpdateMerchantReferenceRequest();
        $request->setOrderId(5601709);
        $request->setNewMerchantReference('493792664');

        return $request;
    }

    private function client(): OrderManagement
    {
        $idGenerator = $this->createMock(IdentityGeneratorInterface::class);
        $idGenerator->method('generateId')->willReturn('generated-id');

        $containerMapper = $this->createMock(ContainerMapper::class);
        $containerMapper->method('toArray')->willReturn([]);
        $containerMapper->method('fromArray')->willReturn($this->empty);

        return new OrderManagement(
            $this->service,
            $this->createMock(Json::class),
            $containerMapper,
            $this->createMock(LogManager::class),
            $idGenerator
        );
    }
}

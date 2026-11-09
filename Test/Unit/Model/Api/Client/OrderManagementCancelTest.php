<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api\Client;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\OrderManagementApiException;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\CancelOrderRequest;

/**
 * A cancel of an order Qliro already cancelled is a success. Service hands every refusal over as a
 * TerminalException, so the check for Qliro's code has to read it there.
 */
class OrderManagementCancelTest extends TestCase
{
    private function refusal(string $code): TerminalException
    {
        $exception = new TerminalException('Request to Qliro One has failed');
        $exception->setQliroError($code, 'refused');

        return $exception;
    }

    private function client(\Exception $refusal, ?AdminTransactionResponseInterface $container = null): OrderManagement
    {
        $service = $this->createMock(Service::class);
        $service->method('post')->willThrowException($refusal);
        $containerMapper = $this->createMock(ContainerMapper::class);
        $containerMapper->method('toArray')->willReturn([]);
        $containerMapper->method('fromArray')->willReturn($container);

        return new OrderManagement(
            $service,
            $this->createMock(Json::class),
            $containerMapper,
            $this->createMock(LogManager::class),
            $this->createMock(IdentityGeneratorInterface::class)
        );
    }

    public function testAnOrderQliroAlreadyCancelledIsASuccess(): void
    {
        $container = $this->createMock(AdminTransactionResponseInterface::class);

        $result = $this->client($this->refusal('ORDER_HAS_BEEN_CANCELLED'), $container)
            ->cancelOrder(new CancelOrderRequest());

        self::assertSame($container, $result);
    }

    public function testAnyOtherRefusalStillFails(): void
    {
        $this->expectException(OrderManagementApiException::class);
        $this->expectExceptionMessage('Error [NO_ITEMS_TO_CANCEL]');

        $this->client($this->refusal('NO_ITEMS_TO_CANCEL'))->cancelOrder(new CancelOrderRequest());
    }
}

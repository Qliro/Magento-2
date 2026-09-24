<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin;

use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\TransactionResponse;

/**
 * Three of the setters had no body and three of the getters no return, so the mapper filled
 * two of the five fields of an order management response and the other three read back null.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\TransactionResponse
 */
class TransactionResponseTest extends TestCase
{
    public function testMapsEveryFieldOfTheResponse(): void
    {
        $containerMapper = new ContainerMapper(
            $this->createMock(ObjectManagerInterface::class),
            $this->createMock(LogManager::class)
        );

        /** @var TransactionResponse $response */
        $response = $containerMapper->fromArray(
            [
                'PaymentTransactionId' => 9200451,
                'Status' => 'Created',
                'Type' => 'UpdateItemsWithReversalResponse',
                'ReversalPaymentTransactionId' => 9200450,
                'ReversalPaymentTransactionStatus' => 'Success',
            ],
            new TransactionResponse()
        );

        self::assertSame(9200451, $response->getPaymentTransactionId());
        self::assertSame('Created', $response->getStatus());
        self::assertSame('UpdateItemsWithReversalResponse', $response->getType());
        self::assertSame(9200450, $response->getReversalPaymentTransactionId());
        self::assertSame('Success', $response->getReversalPaymentTransactionStatus());
    }

    public function testEverySetterReturnsTheResponse(): void
    {
        $response = new TransactionResponse();

        self::assertSame($response, $response->setPaymentTransactionId(1));
        self::assertSame($response, $response->setStatus('Created'));
        self::assertSame($response, $response->setType('UpdateItemsResponse'));
        self::assertSame($response, $response->setReversalPaymentTransactionId(2));
        self::assertSame($response, $response->setReversalPaymentTransactionStatus('Success'));
    }
}

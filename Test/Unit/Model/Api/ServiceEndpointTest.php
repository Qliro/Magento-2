<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api;

use GuzzleHttp\Client;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * An endpoint whose placeholder has no value is refused before the call is made
 *
 * @see \Qliro\QliroOne\Model\Api\Service
 */
class ServiceEndpointTest extends TestCase
{
    /**
     * Under strict types a Phrase handed to \Exception was a TypeError, which replaced the
     * refusal with a fatal.
     */
    public function testAnEmptyPlaceholderIsATerminalException(): void
    {
        $this->expectException(TerminalException::class);
        $this->expectExceptionMessage('Endpoint checkout/merchantapi/orders/{OrderId} has no value for OrderId');

        $this->serviceThatNeverCalls()->get('checkout/merchantapi/orders/{OrderId}', ['OrderId' => null]);
    }

    /**
     * @dataProvider emptyValues
     */
    public function testAnEmptyValueLeavesThePlaceholderUnfilled($value, string $method): void
    {
        $this->expectException(TerminalException::class);
        $this->expectExceptionMessage('has no value for OrderId');

        $this->serviceThatNeverCalls()->{$method}('checkout/merchantapi/orders/{OrderId}', ['OrderId' => $value]);
    }

    public static function emptyValues(): array
    {
        return [
            'empty string on get' => ['', 'get'],
            'false on get' => [false, 'get'],
            'empty string on put' => ['', 'put'],
            'missing on post' => [null, 'post'],
        ];
    }

    public function testZeroStillFillsThePlaceholder(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('request')
            ->with('GET', 'https://pago.qit.nu/checkout/merchantapi/orders/0')
            ->willReturn(new \GuzzleHttp\Psr7\Response(200, [], ''));
        $service = new Service(
            $this->createMock(Config::class),
            $client,
            $this->createMock(Json::class),
            $this->createMock(Manager::class)
        );

        self::assertSame([], $service->get('checkout/merchantapi/orders/{OrderId}', ['OrderId' => 0]));
    }

    private function serviceThatNeverCalls(): Service
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('request');

        return new Service(
            $this->createMock(Config::class),
            $client,
            $this->createMock(Json::class),
            $this->createMock(Manager::class)
        );
    }
}

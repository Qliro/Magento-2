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
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('request');
        $service = new Service(
            $this->createMock(Config::class),
            $client,
            $this->createMock(Json::class),
            $this->createMock(Manager::class)
        );

        $this->expectException(TerminalException::class);
        $this->expectExceptionMessage('Endpoint checkout/merchantapi/orders/{OrderId} has no value for OrderId');

        $service->get('checkout/merchantapi/orders/{OrderId}', ['OrderId' => null]);
    }
}

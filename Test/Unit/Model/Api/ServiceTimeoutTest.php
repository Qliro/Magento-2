<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * PLIN-363: the client ran with Guzzle's defaults, which are no connect timeout and no request
 * timeout, inside the customer's own request.
 *
 * @see \Qliro\QliroOne\Model\Api\Service
 */
class ServiceTimeoutTest extends TestCase
{
    /**
     * @var array<string, mixed> The options the last call handed Guzzle
     */
    private array $options = [];

    private Config&MockObject $config;

    private Client&MockObject $client;

    protected function setUp(): void
    {
        $this->options = [];
        $this->config = $this->createMock(Config::class);
        $this->config->method('getApiType')->willReturn('sandbox');
        $this->config->method('getMerchantApiKey')->willReturn('key');
        $this->config->method('getMerchantApiSecret')->willReturn('secret');
        $this->client = $this->createMock(Client::class);
    }

    /**
     * Without both of these Guzzle waits for as long as the connection stays open, and the
     * checkout call that does it sits inside the customer's request.
     */
    public function testEveryCallCarriesBothTimeouts(): void
    {
        $this->config->method('getApiConnectTimeout')->willReturn(5);
        $this->config->method('getApiRequestTimeout')->willReturn(15);
        $this->respond();

        $this->service()->post('checkout/merchantapi/orders', ['OrderId' => 1]);

        self::assertSame(5, $this->options[RequestOptions::CONNECT_TIMEOUT]);
        self::assertSame(15, $this->options[RequestOptions::TIMEOUT]);
    }

    /**
     * A GET takes the same treatment as a POST: the checkout reads the order back on every
     * refresh, and that read hung exactly like the write.
     */
    public function testAGetCarriesThemToo(): void
    {
        $this->config->method('getApiConnectTimeout')->willReturn(5);
        $this->config->method('getApiRequestTimeout')->willReturn(15);
        $this->respond();

        $this->service()->get('checkout/merchantapi/orders/{OrderId}', ['OrderId' => 1]);

        self::assertSame(5, $this->options[RequestOptions::CONNECT_TIMEOUT]);
        self::assertSame(15, $this->options[RequestOptions::TIMEOUT]);
    }

    /**
     * The call says which pair it wants, because the client class it goes through cannot: the
     * same class serves a checkout page fetch and the status push that follows it.
     */
    public function testTheCallChoosesTheProfile(): void
    {
        $this->config->expects(self::once())
            ->method('getApiConnectTimeout')
            ->with(Config::API_PROFILE_BACKGROUND, 3)
            ->willReturn(5);
        $this->config->expects(self::once())
            ->method('getApiRequestTimeout')
            ->with(Config::API_PROFILE_BACKGROUND, 3)
            ->willReturn(60);
        $this->respond();

        // An instance with the interactive default, asked for the background pair by the call
        $this->service()->get('checkout/merchantapi/orders/{OrderId}', ['OrderId' => 1], 3, Config::API_PROFILE_BACKGROUND);

        self::assertSame(60, $this->options[RequestOptions::TIMEOUT]);
    }

    /**
     * A call that names no profile keeps the one the instance was built with, so nothing that
     * constructs a Service by hand loses its timeouts.
     */
    public function testACallThatNamesNoProfileKeepsTheInstanceDefault(): void
    {
        $this->config->expects(self::once())
            ->method('getApiRequestTimeout')
            ->with(Config::API_PROFILE_BACKGROUND, null)
            ->willReturn(60);
        $this->config->method('getApiConnectTimeout')->willReturn(5);
        $this->respond();

        $this->service(Config::API_PROFILE_BACKGROUND)->post('checkout/adminapi/v2/orders/capture', []);

        self::assertSame(60, $this->options[RequestOptions::TIMEOUT]);
    }

    /**
     * The store decides the values and the store is only known here, per call, not when the
     * shared client is built.
     */
    public function testTheValuesAreReadForTheCallsOwnStoreAndCallType(): void
    {
        $this->config->expects(self::once())
            ->method('getApiConnectTimeout')
            ->with(Config::API_PROFILE_BACKGROUND, 3)
            ->willReturn(5);
        $this->config->expects(self::once())
            ->method('getApiRequestTimeout')
            ->with(Config::API_PROFILE_BACKGROUND, 3)
            ->willReturn(60);
        $this->respond();

        $this->service(Config::API_PROFILE_BACKGROUND)
            ->post('checkout/adminapi/v2/orders/capture', [], 3);

        self::assertSame(60, $this->options[RequestOptions::TIMEOUT]);
    }

    /**
     * An instance nobody configured calls with the shorter pair, the one somebody waits for.
     */
    public function testTheInteractiveProfileIsTheDefault(): void
    {
        $this->config->expects(self::once())
            ->method('getApiRequestTimeout')
            ->with(Config::API_PROFILE_INTERACTIVE, null)
            ->willReturn(15);
        $this->config->method('getApiConnectTimeout')->willReturn(5);
        $this->respond();

        $this->service()->post('checkout/merchantapi/orders', []);
    }

    /**
     * A call that runs out of time is a refusal the checkout controllers already answer with a
     * customer facing message, not a fatal that leaves the customer on a spinner.
     */
    public function testATimedOutCallBecomesATerminalException(): void
    {
        $this->config->method('getApiConnectTimeout')->willReturn(5);
        $this->config->method('getApiRequestTimeout')->willReturn(15);
        $this->client->method('request')->willThrowException(new ConnectException(
            'cURL error 28: Operation timed out after 15001 milliseconds',
            new Request('POST', 'https://pago.qit.nu/checkout/merchantapi/orders')
        ));

        $this->expectException(TerminalException::class);
        $this->expectExceptionMessage('cURL error 28');

        $this->service()->post('checkout/merchantapi/orders', []);
    }

    /**
     * Answer the call, and keep the options it was made with.
     */
    private function respond(): void
    {
        $this->client->method('request')->willReturnCallback(
            function ($method, $uri, array $options) {
                $this->options = $options;

                return $this->createMock(ResponseInterface::class);
            }
        );
    }

    private function service(string $profile = Config::API_PROFILE_INTERACTIVE): Service
    {
        $json = $this->createMock(Json::class);
        $json->method('serialize')->willReturn('{}');
        $json->method('unserialize')->willReturn([]);

        return new Service($this->config, $this->client, $json, $this->createMock(Manager::class), $profile);
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * A refusal the caller handles is logged as info, any other stays an error
 *
 * @see Service::post()
 */
class ServiceExpectedErrorTest extends TestCase
{
    private const CODE = 'UPDATE_MERCHANT_REFERENCE_NOT_SUPPORTED';
    private const ALREADY_SET = "Order has reference set to '493792664' already";

    private Manager&MockObject $logManager;

    /**
     * @var string[]
     */
    private array $levels = [];

    protected function setUp(): void
    {
        $this->logManager = $this->createMock(Manager::class);

        foreach (['debug', 'info', 'error'] as $level) {
            $this->logManager->method($level)->willReturnCallback(function ($message) use ($level) {
                if (str_starts_with((string)$message, '<<< Exception')) {
                    $this->levels[] = $level;
                }
            });
        }
    }

    public function testTheExpectedRefusalIsInfo(): void
    {
        $exception = $this->post(self::ALREADY_SET, $this->alreadySet(...));

        self::assertSame(['info'], $this->levels);
        self::assertSame(self::CODE, $exception->getQliroErrorCode());
        self::assertSame(self::ALREADY_SET, $exception->getQliroErrorMessage());
    }

    public function testTheSameCodeWithAnotherMessageIsAnError(): void
    {
        $this->post("Order with reference '493792664' already exists", $this->alreadySet(...));

        self::assertSame(['error'], $this->levels);
    }

    public function testARefusalNobodyExpectsIsAnError(): void
    {
        $this->post(self::ALREADY_SET, null);

        self::assertSame(['error'], $this->levels);
    }

    private function alreadySet(?string $code, ?string $message): bool
    {
        return $code === self::CODE && $message === self::ALREADY_SET;
    }

    private function post(string $message, ?callable $expected): TerminalException
    {
        $body = (string)json_encode(['ErrorCode' => self::CODE, 'ErrorMessage' => $message]);
        $client = $this->createMock(Client::class);
        $client->method('request')->willThrowException(new ClientException(
            'Client error: 400 Bad Request',
            new Request('POST', 'https://pago.qit.nu/checkout/adminapi/v2/updatemerchantreference'),
            new Response(400, [], $body)
        ));

        $config = $this->createMock(Config::class);
        $config->method('getApiType')->willReturn('sandbox');
        $config->method('getMerchantApiKey')->willReturn('key');
        $config->method('getMerchantApiSecret')->willReturn('secret');
        $config->method('getApiConnectTimeout')->willReturn(5);
        $config->method('getApiRequestTimeout')->willReturn(60);

        $service = new Service($config, $client, new Json(), $this->logManager, Config::API_PROFILE_BACKGROUND);

        try {
            $service->post('checkout/adminapi/v2/updatemerchantreference', [], null, null, $expected);
        } catch (TerminalException $exception) {
            return $exception;
        }

        self::fail('The refusal was not thrown');
    }
}

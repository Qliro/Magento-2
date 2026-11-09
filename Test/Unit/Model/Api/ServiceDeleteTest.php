<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * @see \Qliro\QliroOne\Model\Api\Service::delete
 */
class ServiceDeleteTest extends TestCase
{
    public function testDeleteSendsASignedJsonBodyForTheStore(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getMerchantApiKey')->with(7)->willReturn('key-7');
        $config->method('getMerchantApiSecret')->with(7)->willReturn('secret-7');
        $config->method('getApiType')->willReturn('sandbox');
        $json = $this->createMock(Json::class);
        $json->method('serialize')->willReturnCallback(fn ($data) => json_encode($data));
        $json->method('unserialize')->willReturnCallback(fn ($data) => json_decode($data, true));

        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('request')
            ->willReturnCallback(function ($method, $uri, $options) {
                $body = '{"MerchantSavedCreditCardId":"card-1","MerchantApiKey":"key-7"}';
                self::assertSame('DELETE', $method);
                self::assertSame('https://pago.qit.nu/checkout/merchantapi/RemoveMerchantSavedCreditCard', $uri);
                self::assertSame($body, $options[RequestOptions::BODY]);
                self::assertSame(
                    'Qliro ' . base64_encode(hash('sha256', $body . 'secret-7', true)),
                    $options[RequestOptions::HEADERS]['Authorization']
                );
                return new Response(200, [], '');
            });

        $service = new Service($config, $client, $json, $this->createMock(Manager::class));

        self::assertSame(
            [],
            $service->delete(
                'checkout/merchantapi/RemoveMerchantSavedCreditCard',
                ['MerchantSavedCreditCardId' => 'card-1'],
                7
            )
        );
    }
}

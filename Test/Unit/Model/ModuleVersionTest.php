<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\ModuleVersion;

/**
 * Qliro sees which release a merchant runs on every call, so failures can be told apart by version
 *
 * @see \Qliro\QliroOne\Model\ModuleVersion
 */
class ModuleVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/qliro-module-version-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testTheVersionIsTheOneComposerJsonDeclares(): void
    {
        $composer = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        self::assertSame($composer['version'], ModuleVersion::get());
    }

    /**
     * @dataProvider unreadable
     */
    public function testWhatCannotBeReadIsUnknown(?string $contents): void
    {
        $path = $this->dir . '/composer.json';

        if ($contents !== null) {
            file_put_contents($path, $contents);
        }

        self::assertSame(ModuleVersion::UNKNOWN, ModuleVersion::read($path));
    }

    public static function unreadable(): array
    {
        return [
            'missing file' => [null],
            'not json' => ['{'],
            'no version' => ['{"name":"qliro/module-qliroone"}'],
            'not a string' => ['{"version":1}'],
            'not a version' => ['{"version":"1.8.0\r\nX-Injected: yes"}'],
        ];
    }

    public function testEveryCallCarriesTheVersionHeader(): void
    {
        $options = [];
        $config = $this->createMock(Config::class);
        $config->method('getApiType')->willReturn('sandbox');
        $config->method('getMerchantApiKey')->willReturn('key');
        $config->method('getMerchantApiSecret')->willReturn('secret');
        $client = $this->createMock(Client::class);
        $client->method('request')->willReturnCallback(
            function ($method, $uri, array $requestOptions) use (&$options) {
                $options = $requestOptions;

                return $this->createMock(ResponseInterface::class);
            }
        );
        $json = $this->createMock(Json::class);
        $json->method('serialize')->willReturn('{}');
        $json->method('unserialize')->willReturn([]);
        $service = new Service($config, $client, $json, $this->createMock(Manager::class));

        $service->post('checkout/adminapi/v2/MarkItemsAsShipped', ['OrderId' => 1]);

        self::assertSame(ModuleVersion::get(), $options[RequestOptions::HEADERS][Service::HEADER_MODULE_VERSION]);
        self::assertSame('Magento', $options[RequestOptions::HEADERS][Service::HEADER_PLATFORM]);
    }
}

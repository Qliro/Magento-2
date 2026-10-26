<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Console;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Console\AbstractCommand;
use Qliro\QliroOne\Console\GetOrderCommand;
use Qliro\QliroOne\Console\LockCommand;
use Qliro\QliroOne\Console\TestCommand;
use Qliro\QliroOne\Console\UnlockCommand;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Api\Client\Merchant;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Api\ServiceFactory;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\ContainerMapperFactory;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Redactor;
use Qliro\QliroOne\Model\Management;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The commands print Qliro's data as text, a % or a tag in it is printed as it is
 *
 * @see \Qliro\QliroOne\Console\AbstractCommand
 */
class ApiCommandOutputTest extends TestCase
{
    private const TEXT = 'Smör 100% and %s %d <info>%1$s</info> https://x.se/a';
    private const URI = 'https://pay.qliro.com/checkout/merchantapi/Orders/42';

    public function testGetOrderPrintsThePayloadUnchanged(): void
    {
        $tester = $this->runCommand(new GetOrderCommand($this->factory()), $this->getOrderServices());

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString(
            '"Description": "' . self::TEXT . '"',
            $tester->getDisplay()
        );
    }

    /**
     * An order is placed only when asked for, "false" is not asking
     *
     * @dataProvider createProvider
     */
    public function testGetOrderPlacesAnOrderOnlyWhenAskedTo(array $input, bool $placed): void
    {
        $management = $this->createMock(Management::class);
        $management->expects($placed ? self::once() : self::never())->method('placeOrder');

        $tester = $this->runCommand(
            new GetOrderCommand($this->factory()),
            $this->getOrderServices() + [Management::class => $management],
            $input
        );

        self::assertSame(0, $tester->getStatusCode());
    }

    public static function createProvider(): array
    {
        return [
            'not given' => [[], false],
            'false' => [['create' => 'false'], false],
            '0' => [['create' => '0'], false],
            '1' => [['create' => '1'], true],
            'true' => [['create' => 'true'], true],
        ];
    }

    public function testGetOrderRefusesACreateValueItCannotRead(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\InvalidArgumentException::class);

        $this->runCommand(new GetOrderCommand($this->factory()), $this->getOrderServices(), ['create' => 'y']);
    }

    /**
     * Service reads the response body to find Qliro's error code before it wraps the exception
     */
    public function testGetOrderPrintsWhatWasSentAndAnsweredWhenQliroRefuses(): void
    {
        $request = new Request(
            'PUT',
            self::URI,
            ['Authorization' => 'Qliro c2lnbmF0dXJl'],
            '{"MerchantApiKey":"KEY123","Description":"100%"}'
        );
        $response = new Response(400, [], '{"ErrorMessage":"' . self::TEXT . '","MobileNumber":"+46701234567"}');
        (string) $response->getBody();
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getOrder')->willThrowException(
            $this->clientFailure(new RequestException('Client error: 400 {"MobileNumber":"+46701234567', $request, $response))
        );

        $tester = $this->runCommand(new GetOrderCommand($this->factory()), [Merchant::class => $merchant]);

        $lines = explode("\n", $this->tail($tester), 4);
        $printed = json_decode($lines[3], true);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('Merchant API exception:', $lines[0]);
        self::assertSame('Request to Qliro One has failed.', $lines[1]);
        self::assertStringStartsWith('Client error: 400', $lines[2]);
        self::assertSame(self::URI, $printed['request.uri']);
        self::assertSame('PUT', $printed['request.method']);
        self::assertSame(Redactor::MASK, $printed['request.headers']['Authorization']);
        self::assertSame('{"MerchantApiKey":"[redacted]","Description":"100%"}', $printed['request.body']);
        self::assertSame(400, $printed['response.status']);
        self::assertSame('{"ErrorMessage":"' . self::TEXT . '","MobileNumber":"[redacted]"}', $printed['response.body']);
        self::assertStringNotContainsString('+46701234567', $tester->getDisplay());
    }

    /**
     * A timeout or a refused connection has a request and no response
     */
    public function testGetOrderPrintsAFailureWithoutAResponse(): void
    {
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getOrder')->willThrowException(
            $this->clientFailure(new ConnectException('cURL error 28: ' . self::TEXT, new Request('GET', self::URI)))
        );

        $tester = $this->runCommand(new GetOrderCommand($this->factory()), [Merchant::class => $merchant]);

        $lines = explode("\n", $this->tail($tester), 4);
        $printed = json_decode($lines[3], true);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(
            ['Merchant API exception:', 'Request to Qliro One has failed.', 'cURL error 28: ' . self::TEXT],
            array_slice($lines, 0, 3)
        );
        self::assertSame(self::URI, $printed['request.uri']);
        self::assertSame('GET', $printed['request.method']);
        self::assertArrayNotHasKey('response.status', $printed);
    }

    public function testGetOrderPrintsAPlacementFailureThatHasNoPrevious(): void
    {
        $management = $this->createMock(Management::class);
        $management->method('placeOrder')->willThrowException(new TerminalException(self::TEXT));

        $tester = $this->runCommand(
            new GetOrderCommand($this->factory()),
            $this->getOrderServices() + [Management::class => $management],
            ['create' => '1']
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringEndsWith("\nOrder placement failed:\n" . self::TEXT . "\n", $tester->getDisplay());
    }

    /**
     * The lock is a row in the database, a lock not taken or not released is a false, not an exception
     *
     * @dataProvider lockProvider
     */
    public function testLockAndUnlockExitWithWhetherTheyDidIt(string $class, string $method, bool $result, int $code): void
    {
        $lock = $this->createMock(Lock::class);
        $lock->method($method)->willReturn($result);

        $tester = $this->runCommand(new $class($this->factory()), [Lock::class => $lock]);

        self::assertSame($code, $tester->getStatusCode());
    }

    public static function lockProvider(): array
    {
        return [
            'locked' => [LockCommand::class, 'lock', true, 0],
            'not locked' => [LockCommand::class, 'lock', false, 1],
            'unlocked' => [UnlockCommand::class, 'unlock', true, 0],
            'not unlocked' => [UnlockCommand::class, 'unlock', false, 1],
        ];
    }

    /**
     * Service throws a TerminalException with Guzzle's exception inside
     */
    public function testTestCommandPrintsWhatWasSentAndAnswered(): void
    {
        $response = new Response(401, [], self::TEXT);
        (string) $response->getBody();
        $guzzle = new RequestException('Client error: 401', new Request('POST', self::URI), $response);
        $service = $this->createMock(Service::class);
        $service->method('post')->willThrowException(new TerminalException($guzzle->getMessage(), 401, $guzzle));
        $serviceFactory = $this->createMock(ServiceFactory::class);
        $serviceFactory->method('create')->willReturn($service);
        $mapperFactory = $this->createMock(ContainerMapperFactory::class);
        $mapperFactory->method('create')->willReturn($this->createMock(ContainerMapper::class));
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $tester = $this->runCommand(new TestCommand(
            $this->factory(),
            $serviceFactory,
            $mapperFactory,
            $this->createMock(QliroOrderCreateRequestInterface::class),
            $storeManager
        ), []);

        $lines = explode("\n", $this->tail($tester), 3);
        $printed = json_decode($lines[2], true);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(['Merchant API exception:', 'Client error: 401'], array_slice($lines, 0, 2));
        self::assertSame(self::URI, $printed['request.uri']);
        self::assertSame(self::TEXT, $printed['response.body']);
    }

    /**
     * The chain Service and Merchant build: Guzzle's exception in a TerminalException in a ClientException
     *
     * @param \Exception $guzzle
     * @return ClientException
     */
    private function clientFailure(\Exception $guzzle): ClientException
    {
        return new ClientException(
            new Phrase('Request to Qliro One has failed.'),
            new TerminalException($guzzle->getMessage(), (int) $guzzle->getCode(), $guzzle)
        );
    }

    /**
     * @return array
     */
    private function getOrderServices(): array
    {
        $merchant = $this->createMock(Merchant::class);
        $merchant->method('getOrder')->with('42')->willReturn($this->createMock(QliroOrderInterface::class));
        $mapper = $this->createMock(ContainerMapper::class);
        $mapper->method('toArray')->willReturn(['OrderItems' => [['Description' => self::TEXT]]]);

        return [Merchant::class => $merchant, ContainerMapper::class => $mapper];
    }

    /**
     * @return ObjectManagerFactory
     */
    private function factory(): ObjectManagerFactory
    {
        return $this->createMock(ObjectManagerFactory::class);
    }

    /**
     * @param AbstractCommand $command
     * @param array $services
     * @param array $input
     * @return CommandTester
     */
    private function runCommand(AbstractCommand $command, array $services, array $input = []): CommandTester
    {
        $services += [Redactor::class => new Redactor()];
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(
            fn (string $type) => $services[$type] ?? $this->createMock($type)
        );
        $property = new \ReflectionProperty(AbstractCommand::class, 'objectManager');
        $property->setValue($command, $objectManager);

        $tester = new CommandTester($command);
        $tester->execute($command->getDefinition()->hasArgument('orderid') ? $input + ['orderid' => '42'] : $input);

        return $tester;
    }

    /**
     * The display without the command's own heading line
     *
     * @param CommandTester $tester
     * @return string
     */
    private function tail(CommandTester $tester): string
    {
        return substr($tester->getDisplay(), strpos($tester->getDisplay(), "\n") + 1);
    }
}

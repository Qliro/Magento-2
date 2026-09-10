<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Security;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Security\CallbackToken;
use Qliro\QliroOne\Model\Security\Jwt;

/**
 * @see \Qliro\QliroOne\Model\Security\CallbackToken
 */
class CallbackTokenTest extends TestCase
{
    private const API_KEY = 'live-8f14e45fceea167a5a36dedd4bea2543';
    private const API_SECRET = 'c20ad4d76fe97759aa27a0c99bff6710';

    /**
     * A callback whose token names another merchant used to write both API keys into the log, the
     * store's own included, under key names no masking recognises. The line now carries
     * fingerprints, which still tell an operator whether the two differ.
     */
    public function testTheMerchantMismatchLineCarriesNoApiKey(): void
    {
        $logged = [];
        $token = $this->verifyWith(['merchant' => 'live-someone-else'], $logged);

        self::assertFalse($token);
        self::assertSame('merchant ID mismatch', $logged[0]['message']);

        $written = json_encode($logged[0]['context']);
        self::assertStringNotContainsString(self::API_KEY, $written);
        self::assertStringNotContainsString('live-someone-else', $written);
        self::assertStringNotContainsString(self::API_SECRET, $written);

        $extra = $logged[0]['context']['extra'];
        self::assertStringStartsWith('sha256:', $extra['configured']);
        self::assertStringStartsWith('sha256:', $extra['request']);
        self::assertNotSame($extra['configured'], $extra['request']);
    }

    /**
     * An expired token used to be logged with its whole payload, and the payload's merchant claim
     * is the store's own API key.
     */
    public function testTheExpiredLineCarriesNoApiKey(): void
    {
        $logged = [];
        $token = $this->verifyWith(
            [
                'merchant' => self::API_KEY,
                'expires' => '2020-01-01 00:00:00',
                'additional_data' => null,
            ],
            $logged
        );

        self::assertFalse($token);
        self::assertStringContainsString('expired', $logged[0]['message']);

        $written = json_encode($logged[0]['context']);
        self::assertStringNotContainsString(self::API_KEY, $written);
        self::assertSame('2020-01-01 00:00:00', $logged[0]['context']['extra']['expires']);
        self::assertStringStartsWith('sha256:', $logged[0]['context']['extra']['merchant']);
    }

    /**
     * The additional data of a presented token is content anyone who can post a callback decides,
     * so it is not written out either.
     */
    public function testTheAdditionalDataMismatchLineCarriesNoClaim(): void
    {
        $logged = [];
        $token = $this->verifyWith(
            ['merchant' => self::API_KEY, 'additional_data' => 'whatever-the-caller-sent'],
            $logged
        );

        self::assertFalse($token);
        self::assertSame('additional data mismatch', $logged[0]['message']);
        self::assertStringNotContainsString(
            'whatever-the-caller-sent',
            (string)json_encode($logged[0]['context'])
        );
        self::assertStringStartsWith('sha256:', $logged[0]['context']['extra']['request']);
    }

    /**
     * The same key gives the same fingerprint, which is what makes the two comparable at all.
     */
    public function testTheFingerprintOfTheSameKeyMatches(): void
    {
        $logged = [];
        $this->verifyWith(['merchant' => 'live-someone-else'], $logged);
        $first = $logged[0]['context']['extra']['configured'];

        $logged = [];
        $this->verifyWith(['merchant' => 'live-someone-else-again'], $logged);

        self::assertSame($first, $logged[0]['context']['extra']['configured']);
    }

    /**
     * @param array $payload What the token decodes to
     * @param array $logged Filled with the lines the manager was asked to write
     * @return bool
     */
    private function verifyWith(array $payload, array &$logged): bool
    {
        $jwt = $this->createMock(Jwt::class);
        $jwt->method('decode')->willReturn($payload);

        $config = $this->createMock(Config::class);
        $config->method('getMerchantApiKey')->willReturn(self::API_KEY);
        $config->method('getMerchantApiSecret')->willReturn(self::API_SECRET);

        $logManager = $this->createMock(LogManager::class);
        $logManager->method('debug')
            ->willReturnCallback(function ($message, array $context = []) use (&$logged): void {
                $logged[] = ['message' => $message, 'context' => $context];
            });

        return (new CallbackToken($jwt, $config, $logManager))->verifyToken('a.b.c');
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Security;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Security\CallbackToken;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Model\Security\Jwt;

/**
 * @see \Qliro\QliroOne\Model\Security\CallbackToken
 */
class CallbackTokenTest extends TestCase
{
    private const API_KEY = 'live-8f14e45fceea167a5a36dedd4bea2543';
    private const API_SECRET = 'c20ad4d76fe97759aa27a0c99bff6710';

    /**
     * @var array<int, array{level: string, message: string, context: array}>
     */
    private array $logged = [];

    /**
     * The token this store issues is the token this store accepts.
     */
    public function testAcceptsTheTokenItIssued(): void
    {
        $token = $this->buildToken();

        self::assertTrue($this->buildToken()->verifyToken($token->getToken()));
    }

    /**
     * The token is a query parameter of an endpoint anyone can post to, so it is whatever the
     * caller sent. Nothing that is not a token is read as one: a 500 tells a prober that the
     * shape mattered, and a refusal tells them nothing.
     *
     * @dataProvider notATokenProvider
     */
    public function testRefusesAnythingThatIsNotAToken(mixed $token): void
    {
        self::assertFalse($this->buildToken()->verifyToken($token));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notATokenProvider(): array
    {
        return [
            'an array, as `?token[]=x` arrives' => [['x']],
            'nothing' => [null],
            'the empty string' => [''],
            'a number' => [42],
            'an object' => [(object)['token' => 'x']],
            'one segment' => ['nonsense'],
            'two segments' => ['a.b'],
            'segments that are not base64' => ['@.@.@'],
            'a head that is not an object' => ['NQ.NQ.NQ'],
        ];
    }

    /**
     * A token whose payload was rewritten no longer carries our signature.
     */
    public function testRefusesATamperedToken(): void
    {
        $callbackToken = $this->buildToken();
        [$head, , $signature] = explode('.', $callbackToken->getToken());
        $forged = (string)json_encode(['merchant' => self::API_KEY, 'expires' => '2099-01-01 00:00:00']);
        $forgedBody = str_replace('=', '', strtr(base64_encode($forged), '+/', '-_'));

        self::assertFalse($callbackToken->verifyToken($head . '.' . $forgedBody . '.' . $signature));
    }

    /**
     * A token that has run out is refused, and the line says so loudly enough for a merchant to
     * find it: an expired callback token means Qliro pushed to a url that outlived it.
     */
    public function testRefusesAnExpiredTokenAndWarnsAboutIt(): void
    {
        $expired = $this->buildToken(-1)->getToken();

        self::assertFalse($this->buildToken()->verifyToken($expired));
        self::assertSame('warning', $this->logged[0]['level']);
        self::assertStringContainsString('expired', $this->logged[0]['message']);
    }

    /**
     * A token issued for another merchant is refused.
     */
    public function testRefusesATokenIssuedForAnotherMerchant(): void
    {
        $other = $this->buildToken(30, 'live-someone-else');

        self::assertFalse($this->buildToken()->verifyToken($other->getToken()));
        self::assertSame('merchant ID mismatch', $this->logged[0]['message']);
    }

    /**
     * Nothing a caller could use is written when a check fails: not the configured merchant key,
     * not the presented one, and not the token itself.
     *
     * @dataProvider failingTokenProvider
     */
    public function testAFailedCheckLeaksNothing(int $days, string $merchant): void
    {
        $token = $this->buildToken($days, $merchant)->getToken();

        self::assertFalse($this->buildToken()->verifyToken($token));

        $written = (string)json_encode($this->logged);

        self::assertStringNotContainsString(self::API_KEY, $written, 'the configured key is readable');
        self::assertStringNotContainsString(self::API_SECRET, $written, 'the secret is readable');
        self::assertStringNotContainsString($merchant, $written, 'the presented merchant is readable');
        self::assertStringNotContainsString($token, $written, 'the token is readable');
        self::assertStringNotContainsString(explode('.', $token)[2], $written, 'the signature is readable');
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function failingTokenProvider(): array
    {
        return [
            'another merchant' => [30, 'live-someone-else'],
            'expired' => [-1, self::API_KEY],
        ];
    }

    /**
     * The lifetime is the merchant's setting, and the token says so.
     *
     * @dataProvider lifetimeProvider
     */
    public function testMintsATokenThatLastsTheConfiguredNumberOfDays(int $configured, int $expected): void
    {
        $expiresAt = $this->buildToken($configured)->getExpirationTimestamp();

        self::assertEqualsWithDelta(time() + $expected * 86400, $expiresAt, 60);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function lifetimeProvider(): array
    {
        return [
            'the default' => [365, 365],
            'a short window' => [30, 30],
            'the longest window' => [Config::MAX_CALLBACK_TOKEN_LIFETIME_DAYS, Config::MAX_CALLBACK_TOKEN_LIFETIME_DAYS],
        ];
    }

    /**
     * A token whose header names another algorithm is refused, whatever it is signed with: the
     * header is written by whoever sent the token, so it does not get to choose the check.
     */
    public function testRefusesATokenThatNamesAnotherAlgorithm(): void
    {
        $jwt = new Jwt(new Json());
        $token = $jwt->encode(
            ['merchant' => self::API_KEY, 'expires' => date('Y-m-d H:i:s', strtotime('+1 day'))],
            self::API_SECRET,
            'HS512'
        );

        self::assertFalse($this->buildToken()->verifyToken($token));
    }

    /**
     * A checkout token that ran out is a customer with a tab open, not a misconfiguration, so it
     * is not reported as one: the warning about a refused callback belongs to the callback token.
     */
    public function testAnExpiredCheckoutTokenIsNotReportedAsARefusedCallback(): void
    {
        $expired = $this->buildAjaxToken(-1)->getToken();

        self::assertFalse($this->buildAjaxToken()->verifyToken($expired));
        self::assertSame('debug', $this->logged[0]['level']);
        self::assertStringContainsString('checkout token expired', $this->logged[0]['message']);
        self::assertStringNotContainsString('days', (string)json_encode($this->logged[0]['context']));
    }

    /**
     * The checkout token lasts two hours whatever the callback setting says. Built plain, without
     * the double the other checkout cases use, so this asserts the class and not the double.
     */
    public function testTheCheckoutTokenKeepsItsOwnLifetime(): void
    {
        $token = new AjaxToken(new Jwt(new Json()), $this->config(365), $this->logManager());

        self::assertEqualsWithDelta(time() + 7200, $token->getExpirationTimestamp(), 60);
    }

    /**
     * The binding between a checkout token and its quote is the quote id itself, not a shortened
     * hash of it: two ids that agree in the first bytes of a digest must not stand in for one
     * another, and a claim of null must not satisfy a check expecting a quote.
     *
     * @dataProvider mismatchedQuoteProvider
     */
    public function testACheckoutTokenIsBoundToItsOwnQuote(?string $minted, ?string $expected): void
    {
        $token = $this->buildAjaxToken(2, $minted)->getToken();

        self::assertFalse($this->buildAjaxToken(2, $expected)->verifyToken($token));
        self::assertSame('additional data mismatch', $this->logged[0]['message']);
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function mismatchedQuoteProvider(): array
    {
        return [
            'another quote' => ['4711', '4712'],
            'no quote against a quote' => [null, '4711'],
            'a quote against no quote' => ['4711', null],
        ];
    }

    /**
     * The same quote is the same quote.
     */
    public function testACheckoutTokenVerifiesAgainstItsOwnQuote(): void
    {
        $token = $this->buildAjaxToken(2, '4711')->getToken();

        self::assertTrue($this->buildAjaxToken(2, '4711')->verifyToken($token));
    }

    /**
     * A signed token with no readable expiry is refused for that reason, rather than reported as
     * having expired half a lifetime ago, which would send a merchant to the wrong setting. A
     * claim of the wrong type is one of these: reading it as a date is a fatal, and on a callback
     * endpoint a fatal is a 500 where a refusal belongs.
     *
     * @dataProvider unreadableExpiryProvider
     */
    public function testRefusesATokenWithNoReadableExpiry(mixed $expires): void
    {
        $token = (new Jwt(new Json()))->encode(
            ['merchant' => self::API_KEY, 'expires' => $expires, 'additional_data' => null],
            self::API_SECRET
        );

        self::assertFalse($this->buildToken()->verifyToken($token));
        self::assertStringContainsString('no expiry that can be read', $this->logged[0]['message']);
        self::assertArrayNotHasKey('expired', $this->logged[0]['context']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unreadableExpiryProvider(): array
    {
        return [
            'not a date' => ['not a date'],
            'a number' => [1757500000],
            'an array' => [['2026-12-31 00:00:00']],
            'nothing at all' => [null],
        ];
    }

    /**
     * A store that is active with no credentials configured signs with an empty key, which anyone
     * can do, and holds an empty merchant key, which an empty claim matches. Nothing to verify
     * against means nothing verifies, rather than everything verifying.
     *
     * @dataProvider missingCredentialProvider
     */
    public function testRefusesEveryTokenWhenTheStoreHasNoCredentials(string $key, string $secret): void
    {
        $forged = (new Jwt(new Json()))->encode(
            ['merchant' => $key, 'expires' => date('Y-m-d H:i:s', strtotime('+1 day')), 'additional_data' => null],
            $secret
        );

        $config = $this->createMock(Config::class);
        $config->method('getMerchantApiKey')->willReturn($key);
        $config->method('getMerchantApiSecret')->willReturn($secret);
        $config->method('getCallbackTokenLifetimeDays')->willReturn(365);

        $token = new CallbackToken(new Jwt(new Json()), $config, $this->logManager());

        self::assertFalse($token->verifyToken($forged));
        self::assertStringContainsString('no API credentials', $this->logged[0]['message']);
        self::assertSame('debug', $this->logged[0]['level'], 'an anonymous caller can repeat this at will');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function missingCredentialProvider(): array
    {
        return [
            'nothing configured at all' => ['', ''],
            'a key but no secret' => [self::API_KEY, ''],
            'a secret but no key' => ['', self::API_SECRET],
        ];
    }

    /**
     * A token that did not verify leaves a trace. Without one, rotating the API secret stops every
     * registered callback at once and there is nothing in the log to say why.
     */
    public function testATokenThatDidNotVerifyIsLogged(): void
    {
        $token = (new Jwt(new Json()))->encode(
            ['merchant' => self::API_KEY, 'expires' => date('Y-m-d H:i:s', strtotime('+1 day'))],
            'the-secret-this-store-used-to-have'
        );

        self::assertFalse($this->buildToken()->verifyToken($token));
        self::assertSame('debug', $this->logged[0]['level']);
        self::assertStringContainsString('did not verify', $this->logged[0]['message']);
        self::assertStringNotContainsString($token, (string)json_encode($this->logged));
    }

    /**
     * A merchant claim that is not a string is not this store's key.
     */
    public function testRefusesAMerchantClaimThatIsNotAString(): void
    {
        $token = (new Jwt(new Json()))->encode(
            ['merchant' => ['x'], 'expires' => date('Y-m-d H:i:s', strtotime('+1 day'))],
            self::API_SECRET
        );

        self::assertFalse($this->buildToken()->verifyToken($token));
        self::assertSame('merchant ID mismatch', $this->logged[0]['message']);
    }

    /**
     * A token minted before this release, with the three year expiry, is still accepted: the
     * lifetime is written into each token, so shortening the setting cannot invalidate a callback
     * url already registered with Qliro.
     */
    public function testStillAcceptsATokenMintedWithTheOldThreeYearExpiry(): void
    {
        $legacy = (new Jwt(new Json()))->encode(
            [
                'merchant' => self::API_KEY,
                'expires' => date('Y-m-d H:i:s', strtotime('+3 years')),
                'additional_data' => null,
            ],
            self::API_SECRET
        );

        self::assertTrue($this->buildToken(30)->verifyToken($legacy));
    }

    /**
     * @param int $lifetimeDays
     * @param string $merchantKey
     * @return CallbackToken
     */
    private function buildToken(int $lifetimeDays = 365, string $merchantKey = self::API_KEY): CallbackToken
    {
        return new CallbackToken(new Jwt(new Json()), $this->config($lifetimeDays, $merchantKey), $this->logManager());
    }

    /**
     * @param int $hoursFromNow Negative for a token that has already run out
     * @param string|null $quoteId What the token is bound to
     * @return AjaxToken
     */
    private function buildAjaxToken(int $hoursFromNow = 2, ?string $quoteId = null): AjaxToken
    {
        $token = new class (new Jwt(new Json()), $this->config(365), $this->logManager(), $hoursFromNow, $quoteId) extends AjaxToken {
            public function __construct(
                Jwt $jwt,
                Config $config,
                LogManager $logManager,
                private int $hours,
                private ?string $quoteId
            ) {
                parent::__construct($jwt, $config, $logManager);
            }

            // The two hour lifetime is what the class fixes; the test needs one that has passed
            protected function getLifetimeSeconds(): int
            {
                return $this->hours * 3600;
            }

            // Set through a quote in production, which a unit test has no use for
            public function getAdditionalData(): ?string
            {
                return $this->quoteId;
            }
        };

        return $token;
    }

    /**
     * @param int $lifetimeDays
     * @param string $merchantKey
     * @return Config
     */
    private function config(int $lifetimeDays, string $merchantKey = self::API_KEY): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('getMerchantApiKey')->willReturn($merchantKey);
        $config->method('getMerchantApiSecret')->willReturn(self::API_SECRET);
        $config->method('getCallbackTokenLifetimeDays')->willReturn($lifetimeDays);

        return $config;
    }

    /**
     * @return LogManager
     */
    private function logManager(): LogManager
    {
        $logManager = $this->createMock(LogManager::class);
        $logManager->method('log')
            ->willReturnCallback(function ($level, $message, array $context = []): void {
                $this->logged[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
            });

        foreach (['debug', 'warning', 'info', 'error'] as $level) {
            $logManager->method($level)
                ->willReturnCallback(function ($message, array $context = []) use ($level): void {
                    $this->logged[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
                });
        }

        return $logManager;
    }
}

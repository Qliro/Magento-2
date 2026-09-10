<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Security;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Security\Jwt;

/**
 * @see \Qliro\QliroOne\Model\Security\Jwt
 */
class JwtTest extends TestCase
{
    private const SECRET = 'c20ad4d76fe97759aa27a0c99bff6710';

    private Jwt $jwt;

    protected function setUp(): void
    {
        $this->jwt = new Jwt(new Json());
    }

    /**
     * A token this module signed is accepted and gives back what was put in it.
     */
    public function testAcceptsATokenItSignedItself(): void
    {
        $payload = ['merchant' => 'live-key', 'expires' => '2026-12-31 23:59:59', 'additional_data' => null];

        $decoded = $this->jwt->decode($this->jwt->encode($payload, self::SECRET), self::SECRET, true);

        self::assertSame($payload, $decoded);
    }

    /**
     * The signature is what makes the payload trustworthy, so a changed payload is refused even
     * though the token is otherwise well formed.
     */
    public function testRefusesATokenWhosePayloadWasChanged(): void
    {
        $token = $this->jwt->encode(['merchant' => 'live-key'], self::SECRET);
        [$head, , $signature] = explode('.', $token);
        $forgedBody = $this->base64Url((string)json_encode(['merchant' => 'someone-else']));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Signature verification failed');

        $this->jwt->decode($head . '.' . $forgedBody . '.' . $signature, self::SECRET, true);
    }

    /**
     * A signature that is wrong in any way at all is refused, including the shapes a comparison
     * operator would have called equal: `!=` compares two numeric strings as numbers, so a
     * signature of "0" and one of "0.0" or "0e12" are the same value to it.
     *
     * @dataProvider tamperedSignatureProvider
     */
    public function testRefusesATamperedSignature(string $signature): void
    {
        $token = $this->jwt->encode(['merchant' => 'live-key'], self::SECRET);
        [$head, $body] = explode('.', $token);

        $this->expectException(\UnexpectedValueException::class);

        $this->jwt->decode($head . '.' . $body . '.' . $signature, self::SECRET, true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tamperedSignatureProvider(): array
    {
        return [
            'a signature of nothing' => [''],
            'one byte off at the end' => ['AAAA'],
            'a numeric string' => ['MA'],
            'a numeric string in exponent form' => ['MGUxMg'],
            'a zero of another spelling' => ['MC4w'],
        ];
    }

    /**
     * A caller cannot pick the algorithm the token is checked with, so `none` and anything else
     * outside the supported list is refused rather than treated as no signature at all.
     *
     * @dataProvider unsupportedAlgorithmProvider
     */
    public function testRefusesAnUnsupportedAlgorithm(mixed $algorithm, string $exception): void
    {
        $head = $this->base64Url((string)json_encode(['typ' => 'JWT', 'alg' => $algorithm]));
        $body = $this->base64Url((string)json_encode(['merchant' => 'live-key']));

        $this->expectException($exception);

        $this->jwt->decode($head . '.' . $body . '.' . $this->base64Url('signature'), self::SECRET, true);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unsupportedAlgorithmProvider(): array
    {
        return [
            'none' => ['none', \DomainException::class],
            'a signature algorithm we do not implement' => ['RS256', \DomainException::class],
            'no algorithm at all' => ['', \DomainException::class],
            'an algorithm that is not a name' => [['HS256'], \DomainException::class],
        ];
    }

    /**
     * A token signed with another secret is a token from somewhere else.
     */
    public function testRefusesATokenSignedWithAnotherSecret(): void
    {
        $token = $this->jwt->encode(['merchant' => 'live-key'], 'another-secret-entirely');

        $this->expectException(\UnexpectedValueException::class);

        $this->jwt->decode($token, self::SECRET, true);
    }

    /**
     * @dataProvider malformedProvider
     */
    public function testRefusesAMalformedToken(string $token): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->jwt->decode($token, self::SECRET, true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedProvider(): array
    {
        return [
            'nothing' => [''],
            'two segments' => ['a.b'],
            'four segments' => ['a.b.c.d'],
        ];
    }

    private function base64Url(string $value): string
    {
        return str_replace('=', '', strtr(base64_encode($value), '+/', '-_'));
    }
}

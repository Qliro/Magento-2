<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Security;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * JWT-compatible token handling class
 */
class Jwt
{
    /**
     * What this class signs with unless it is told otherwise, and what it verifies against
     */
    public const DEFAULT_ALGORITHM = 'HS256';

    /**
     * @var array
     */
    private $supportedMethods = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $json;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\Serialize\Serializer\Json $json
     */
    public function __construct(
        Json $json
    ) {
        $this->json = $json;
    }

    /**
     * Decode a JWT string into an array
     *
     * @param string $jwt
     * @param string|null $secretKey
     * @param bool $verify Don't skip verification process
     * @param string|null $expectedAlgorithm The algorithm the caller signs with, null trusts the header
     * @return array
     * @throws \UnexpectedValueException
     * @throws \DomainException
     */
    public function decode($jwt, $secretKey = null, $verify = true, $expectedAlgorithm = self::DEFAULT_ALGORITHM)
    {
        $tokenSegments = explode('.', $jwt);

        if (count($tokenSegments) != 3) {
            throw new \UnexpectedValueException('Wrong number of segments.');
        }

        list($encodedHead, $encodedBody, $encryption) = $tokenSegments;

        $header = $this->jsonDecode($this->safeDecode($encodedHead));
        $payload = $this->jsonDecode($this->safeDecode($encodedBody));

        // Both have to decode to something indexable: reading a key off a scalar is a warning
        // that developer mode turns into a throw. A list gets past this and is refused below,
        // when it turns out to name no algorithm
        if (!is_array($header) || !is_array($payload)) {
            throw new \UnexpectedValueException('Invalid segment encoding.');
        }

        $sig = $this->safeDecode($encryption);

        if ($verify) {
            if (empty($header['alg']) || !is_string($header['alg'])) {
                throw new \DomainException('Empty algorithm');
            }

            // The header is written by whoever sent the token, so the caller says what it signs
            // with and the header cannot talk the check into another algorithm. A caller that
            // passes null is trusting the header, which is why that is not the default
            if ($expectedAlgorithm !== null && !\hash_equals((string)$expectedAlgorithm, $header['alg'])) {
                throw new \DomainException('Algorithm is not the expected one.');
            }

            // hash_equals, not a comparison operator: `!=` returns on the first byte that differs,
            // which times how much of a forged signature was right, and PHP compares two numeric
            // looking binary strings as numbers, so `!=` can call two different signatures equal
            $expected = $this->sign("$encodedHead.$encodedBody", $secretKey, $header['alg']);

            if (!\hash_equals($expected, (string)$sig)) {
                throw new \UnexpectedValueException('Signature verification failed.');
            }
        }

        return $payload;
    }

    /**
     * Convert and sign a payload into a JWT string
     *
     * @param array $payload
     * @param string $secretKey
     * @param string $algorithm
     * @return string
     */
    public function encode($payload, $secretKey, $algorithm = self::DEFAULT_ALGORITHM)
    {
        $header = ['typ' => 'JWT', 'alg' => $algorithm];
        $segments = [];
        $segments[] = $this->safeEncode($this->jsonEncode($header));
        $segments[] = $this->safeEncode($this->jsonEncode($payload));
        $signingInput = implode('.', $segments);
        $signature = $this->sign($signingInput, $secretKey, $algorithm);
        $segments[] = $this->safeEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Sign a string with a given key and algorithm
     *
     * @param string $message
     * @param string $secretKey
     * @param string $algorithm
     * @return string
     * @throws \DomainException
     */
    private function sign($message, $secretKey, $algorithm = self::DEFAULT_ALGORITHM)
    {
        if (!isset($this->supportedMethods[$algorithm])) {
            throw new \DomainException('Algorithm is not supported.');
        }

        return \hash_hmac($this->supportedMethods[$algorithm], $message, $secretKey, true);
    }

    /**
     * Decode a JSON string into a PHP object.
     *
     * @param string $input JSON string
     * @return array
     * @throws \DomainException Provided string was invalid JSON
     */
    private function jsonDecode($input)
    {
        try {
            $data = $this->json->unserialize($input);
        } catch (\InvalidArgumentException $exception) {
            throw new \DomainException('Unknown JSON decode error.');
        }

        if ($data === null && $input !== 'null') {
            throw new \DomainException('Null result with non-null input.');
        }

        return $data;
    }

    /**
     * Encode an array into a JSON string
     *
     * @param array $input
     * @return string
     * @throws \DomainException
     */
    private function jsonEncode($input)
    {
        try {
            $json = $this->json->serialize($input);
        } catch (\InvalidArgumentException $exception) {
            throw new \DomainException('Unknown JSON encode error.');
        }

        if ($json === 'null' && $input !== null) {
            throw new \DomainException('Null result with non-null input');
        }

        return $json;
    }

    /**
     * Decode a string with URL-safe Base64
     *
     * @param string $input
     * @return string
     */
    private function safeDecode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }

        return \base64_decode(strtr($input, '-_', '+/'));
    }

    /**
     * Encode a string with URL-safe Base64
     *
     * @param string $input
     * @return string
     */
    private function safeEncode($input)
    {
        return str_replace('=', '', strtr(\base64_encode($input), '+/', '-_'));
    }
}

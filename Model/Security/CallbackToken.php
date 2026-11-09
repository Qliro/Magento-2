<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Security;

use Psr\Log\LogLevel;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * Notification Callback Token handling class
 */
class CallbackToken
{
    /**
     * What this module signs with, and therefore the only algorithm it accepts back
     */
    public const ALGORITHM = 'HS256';

    /**
     * Inject dependencies
     *
     * @param Jwt $jwt
     * @param Config $qliroConfig
     * @param Manager $logManager
     * @param PreviousCredentials $previousCredentials
     * @param TokenLogThrottle $logThrottle
     */
    public function __construct(
        private Jwt $jwt,
        private Config $qliroConfig,
        private Manager $logManager,
        private PreviousCredentials $previousCredentials,
        private TokenLogThrottle $logThrottle
    ) {

    }

    /**
     * Generate and retrieve a JWT token based on the payload data.
     *
     * @return string Encoded JWT token.
     */
    public function getToken(): string
    {
        $payload = [
            'merchant' => $this->qliroConfig->getMerchantApiKey(),
            'expires' => date('Y-m-d H:i:s', $this->getExpirationTimestamp()),
            'additional_data' => $this->getAdditionalData(),
        ];

        return $this->jwt->encode($payload, $this->qliroConfig->getMerchantApiSecret(), self::ALGORITHM);
    }

    /**
     * Verifies the validity of a security token.
     *
     * @param string $token The token to be verified.
     * @return bool Returns true if the token is valid, otherwise false.
     */
    public function verifyToken($token): bool
    {
        // The token is a query parameter of an endpoint open to the internet, so it is whatever
        // the caller sent: `?token[]=x` is an array, and reading that as a string is a fatal,
        // which is a 500 where a refusal belongs
        if (!is_string($token) || $token === '') {
            return false;
        }

        $secret = (string)$this->qliroConfig->getMerchantApiSecret();
        $merchantKey = (string)$this->qliroConfig->getMerchantApiKey();

        $this->logManager->setMark('SECURITY TOKEN');
        $this->logManager->addTag('security');

        try {
            // A store that is active with no credentials configured would otherwise sign with an
            // empty key, which anyone can do, and match an empty merchant claim against its empty
            // key: every token forgeable. Nothing to verify against, so nothing verifies. At debug
            // because the caller is anonymous and can repeat it as often as it likes
            if ($secret === '' || $merchantKey === '') {
                $this->logManager->debug(
                    'token cannot be verified, the store has no API credentials configured',
                    ['extra' => ['has_secret' => $secret !== '', 'has_key' => $merchantKey !== '']]
                );

                return false;
            }

            $reason = '';
            $replaced = null;
            $payload = $this->verifiedPayload($token, $secret, $merchantKey, $reason);

            if ($payload === null) {
                try {
                    $claims = $this->jwt->decode($token, null, false);
                } catch (\Throwable $exception) {
                    $claims = null;
                }

                // Internet noise, or a callback url mangled on the way
                if (!is_array($claims)) {
                    $this->logManager->debug(
                        'token is malformed ({reason}), the request was refused',
                        ['reason' => $reason]
                    );

                    return false;
                }

                $replaced = $this->acceptsReplacedCredentials() ? $this->replacedPairThatSigned($token) : null;

                if ($replaced === null) {
                    $this->reportRefusal($claims, $merchantKey, $reason);

                    return false;
                }

                // The same body the pair just verified
                $payload = $claims;
            }

            $additionalData = $payload['additional_data'] ?? null;
            // Guarded on type: the claim is whatever the token says, and strtotime() of an array
            // is a TypeError, which on a callback endpoint is a 500 instead of a refusal
            $expiresAt = is_string($payload['expires'] ?? null) ? (int)strtotime($payload['expires']) : 0;

            // The values themselves, not their fingerprints: a fingerprint is eight hex characters
            // and two quote ids that collide in those would bind a token to the wrong quote
            if (!hash_equals($this->canonical($this->getAdditionalData()), $this->canonical($additionalData))) {
                $this->logManager->debug(
                    'additional data mismatch',
                    [
                        'extra' => [
                            'request' => $this->fingerprint($additionalData),
                            'configured' => $this->fingerprint($this->getAdditionalData()),
                        ]
                    ]
                );

                return false;
            }

            if (!$expiresAt) {
                // Not an expiry we can read, so not an expiry: this is a malformed token, and
                // saying it expired a billion seconds ago would point at the wrong setting
                $this->logManager->log(
                    $this->getExpiryLogLevel(),
                    'token carries no expiry that can be read, the request was refused',
                    ['extra' => ['expires' => is_scalar($payload['expires'] ?? null) ? $payload['expires'] : null]]
                );

                return false;
            }

            if ($expiresAt - time() < 0) {
                $this->logManager->log(
                    $this->getExpiryLogLevel(),
                    $this->getExpiryMessage(),
                    [
                        'expired' => time() - $expiresAt,
                        'extra' => [
                            'expires' => $payload['expires'] ?? null,
                            'lifetime' => $this->describeLifetime(),
                        ]
                    ]
                );

                return false;
            }

            if ($replaced !== null) {
                $this->logThrottled(
                    'previous',
                    LogLevel::INFO,
                    'token verified with the API credentials replaced at {replaced_at}',
                    [
                        'replaced_at' => date('Y-m-d H:i:s', $replaced['replaced_at']),
                        'extra' => ['key' => $this->fingerprint($replaced['key'])],
                    ]
                );
            }
        } finally {
            $this->logManager->setMark(null);
            $this->logManager->removeTag('security');
        }

        return true;
    }

    /**
     * The payload, when the token is signed with this secret and issued for this key
     *
     * @param string $token
     * @param string $secret
     * @param string $merchantKey
     * @param string $reason Why it is not, when it is not
     * @return array|null
     */
    private function verifiedPayload(string $token, string $secret, string $merchantKey, string &$reason = ''): ?array
    {
        try {
            // Throwable, not Exception: a malformed token must be refused, never reported as a
            // server error, and a TypeError is not an Exception
            $payload = $this->jwt->decode($token, $secret, true, self::ALGORITHM);
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage();

            return null;
        }

        $merchant = is_array($payload) ? ($payload['merchant'] ?? null) : null;

        if (!is_string($merchant) || !hash_equals($merchantKey, $merchant)) {
            $reason = 'merchant mismatch';

            return null;
        }

        return $payload;
    }

    /**
     * The replaced pair within the window that signed the token
     *
     * @param string $token
     * @return array|null
     */
    private function replacedPairThatSigned(string $token): ?array
    {
        foreach ($this->previousCredentials->getActive() as $pair) {
            if ($this->verifiedPayload($token, $pair['secret'], $pair['key']) !== null) {
                return $pair;
            }
        }

        return null;
    }

    /**
     * Say why a well formed token was refused
     *
     * Most likely an older order whose callback url was signed with credentials the store no
     * longer has.
     *
     * @param array $claims
     * @param string $merchantKey
     * @param string $reason
     * @return void
     */
    private function reportRefusal(array $claims, string $merchantKey, string $reason): void
    {
        $merchant = $claims['merchant'] ?? null;
        $presented = $this->fingerprint($merchant);
        $context = [
            'reason' => $reason,
            // Fingerprints, not the keys: the presented one is chosen by the caller
            'extra' => [
                'request' => $presented,
                'configured' => $this->fingerprint($merchantKey),
                'previous_credentials_days' => $this->acceptsReplacedCredentials()
                    ? $this->qliroConfig->getPreviousCredentialsDays()
                    : null,
            ],
        ];

        if (is_string($merchant) && hash_equals($merchantKey, $merchant)) {
            $this->logThrottled(
                'signature',
                $this->getRefusalLogLevel(),
                'token did not verify with the API secret ({reason}), the request was refused. '
                . 'Callbacks of orders created before an API secret change are refused this way',
                $context
            );

            return;
        }

        // Per presented key, and a key the store replaced has a line of its own, so a caller
        // sending made up keys cannot take the line of a real refusal
        $this->logThrottled(
            'merchant',
            $this->getRefusalLogLevel(),
            'token was issued for another merchant API key, the request was refused. '
            . 'Callbacks of orders created before an API key change are refused this way',
            $context,
            $presented,
            $this->getRefusalLogLevel() !== LogLevel::DEBUG
                && is_string($merchant)
                && $this->previousCredentials->isKnownKey($merchant)
        );
    }

    /**
     * Log at the given level once per kind, store and hour, at debug otherwise
     *
     * @param string $kind
     * @param string $level
     * @param string $message
     * @param array $context
     * @param string $variant
     * @param bool $reserved
     * @return void
     */
    private function logThrottled(
        string $kind,
        string $level,
        string $message,
        array $context,
        string $variant = '',
        bool $reserved = false
    ): void {
        if ($level !== LogLevel::DEBUG && !$this->logThrottle->allow(static::class . ':' . $kind, $variant, $reserved)) {
            $level = LogLevel::DEBUG;
        }

        $this->logManager->log($level, $message, $context);
    }

    /**
     * One reading of a value, so that a string, a number and nothing at all cannot be confused
     *
     * The type is part of it: a token claiming `null` must not satisfy a check expecting the
     * empty string, and one claiming the number 7 must not satisfy one expecting "7".
     *
     * @param mixed $value
     * @return string
     */
    private function canonical($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return gettype($value) . ':' . var_export($value, true);
        }

        return 'json:' . (string)\json_encode($value);
    }

    /**
     * A value that can be compared between two log lines without being readable in either
     *
     * @param mixed $value
     * @return string
     */
    private function fingerprint($value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        if (!is_scalar($value)) {
            $value = \json_encode($value);

            if (!\is_string($value)) {
                return 'unreadable';
            }
        }

        return 'sha256:' . substr(hash('sha256', (string)$value), 0, 8);
    }

    /**
     * Get the expiration timestamp.
     *
     * @return int The UNIX timestamp representing the expiration
     */
    public function getExpirationTimestamp(): int
    {
        return time() + $this->getLifetimeSeconds();
    }

    /**
     * How long a token minted now is good for
     *
     * A merchant setting, because the callback urls are registered with Qliro when the order is
     * created and are pushed to for as long as that order can be captured or refunded. It used to
     * be three years, which is not an expiry at all.
     *
     * @return int Seconds
     */
    protected function getLifetimeSeconds(): int
    {
        return $this->qliroConfig->getCallbackTokenLifetimeDays() * 86400;
    }

    /**
     * At what level an expired token is worth reporting
     *
     * A callback whose token ran out is a merchant problem: Qliro pushed to a url that outlived
     * its token, and the setting has to be widened. A checkout tab left open is not.
     *
     * @return string
     */
    protected function getExpiryLogLevel(): string
    {
        return 'warning';
    }

    /**
     * Whether a token signed with an API key and secret pair the store replaced is accepted
     *
     * @return bool
     */
    protected function acceptsReplacedCredentials(): bool
    {
        return true;
    }

    /**
     * At what level a token signed with other credentials is worth reporting
     *
     * @return string
     */
    protected function getRefusalLogLevel(): string
    {
        return LogLevel::WARNING;
    }

    /**
     * @return string
     */
    protected function getExpiryMessage(): string
    {
        return 'token expired {expired} seconds ago, the callback was refused';
    }

    /**
     * The lifetime as the setting that decides it, for the line above
     *
     * @return string
     */
    protected function describeLifetime(): string
    {
        return $this->qliroConfig->getCallbackTokenLifetimeDays() . ' days, from the callback token lifetime setting';
    }

    /**
     * Get additional data used for modifying security token
     *
     * @return string|null Additional data or null if none exists
     */
    public function getAdditionalData(): ?string
    {
        return null;
    }
}

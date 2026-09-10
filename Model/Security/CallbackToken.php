<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Security;

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
     */
    public function __construct(
        private Jwt $jwt,
        private Config $qliroConfig,
        private Manager $logManager
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

            try {
                $payload = $this->jwt->decode($token, $secret, true, self::ALGORITHM);
            } catch (\Throwable $exception) {
                // Throwable, not Exception: a malformed token must be refused, never reported as
                // a server error, and a TypeError is not an Exception.
                //
                // Logged, because without it the whole class of signature failures is invisible:
                // rotate the API secret and every callback url already registered with Qliro
                // stops verifying at once, with nothing to read. At debug, because the caller is
                // anonymous and can repeat it as often as it likes
                $this->logManager->debug(
                    'token did not verify: {reason}',
                    [
                        'reason' => $exception->getMessage(),
                        'extra' => ['exception' => get_class($exception)],
                    ]
                );

                return false;
            }

            if (!is_array($payload)) {
                return false;
            }

            $merchant = $payload['merchant'] ?? null;
            // Guarded on type: the claim is whatever the token says, and strtotime() of an array
            // is a TypeError, which on a callback endpoint is a 500 instead of a refusal
            $expiresAt = is_string($payload['expires'] ?? null) ? (int)strtotime($payload['expires']) : 0;
            $additionalData = $payload['additional_data'] ?? null;

            if (!is_string($merchant) || !hash_equals($merchantKey, $merchant)) {
                $this->logManager->debug(
                    'merchant ID mismatch',
                    [
                        // Fingerprints, not the keys: this told the log the merchant API key on
                        // every mismatch, and the presented one is chosen by the caller
                        'extra' => [
                            'request' => $this->fingerprint($merchant),
                            'configured' => $this->fingerprint($merchantKey),
                        ]
                    ]
                );

                return false;
            }

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
        } finally {
            $this->logManager->setMark(null);
            $this->logManager->removeTag('security');
        }

        return true;
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

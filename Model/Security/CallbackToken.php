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

        return $this->jwt->encode($payload, $this->qliroConfig->getMerchantApiSecret());
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

        // A claim can be a structure, and two different structures must not both read as empty
        if (!is_scalar($value)) {
            $value = \json_encode($value);

            if (!\is_string($value)) {
                return 'unreadable';
            }
        }

        return 'sha256:' . substr(hash('sha256', (string)$value), 0, 8);
    }

    /**
     * Verifies the validity of a security token.
     *
     * @param string $token The token to be verified.
     * @return bool Returns true if the token is valid, otherwise false.
     */
    public function verifyToken($token): bool
    {
        try {
            $payload = $this->jwt->decode($token, $this->qliroConfig->getMerchantApiSecret(), true);
        } catch (\Exception $exception) {
            return false;
        }

        $merchant = $payload['merchant'] ?? null;
        $expiresAt = isset($payload['expires']) ? strtotime($payload['expires']) : 0;
        $additionalData = $payload['additional_data'] ?? null;

        $this->logManager->setMark('SECURITY TOKEN');
        $this->logManager->addTag('security');

        if ($merchant !== $this->qliroConfig->getMerchantApiKey()) {
            $this->logManager->debug(
                'merchant ID mismatch',
                [
                    'extra' => [
                        // Fingerprints, not the keys: this told the log the API key on every mismatch
                        'request' => $this->fingerprint($merchant),
                        'configured' => $this->fingerprint($this->qliroConfig->getMerchantApiKey()),
                    ]
                ]
            );

            $this->logManager->setMark(null);
            $this->logManager->removeTag('security');

            return false;
        }

        if ($additionalData != $this->getAdditionalData()) {
            $this->logManager->debug(
                'additional data mismatch',
                [
                    'extra' => [
                        // A claim of the presented token, so anyone who can post a callback decides
                        // what this says. A fingerprint still tells the two apart
                        'request' => $this->fingerprint($additionalData),
                        'configured' => $this->fingerprint($this->getAdditionalData()),
                    ]
                ]
            );

            $this->logManager->setMark(null);
            $this->logManager->removeTag('security');

            return false;
        }

        if ($expiresAt - time() < 0) {
            $this->logManager->debug(
                'expired {expired} seconds ago',
                [
                    'expired' => time() - $expiresAt,
                    // The payload carries the API key as its merchant claim, so it is not logged whole
                    'extra' => [
                        'merchant' => $this->fingerprint($merchant),
                        'expires' => $payload['expires'] ?? null,
                        'has_additional_data' => $additionalData !== null,
                    ]
                ]
            );

            $this->logManager->setMark(null);
            $this->logManager->removeTag('security');

            return false;
        }

        $this->logManager->setMark(null);
        $this->logManager->removeTag('security');

        return true;
    }

    /**
     * Get the expiration timestamp.
     *
     * @return int The UNIX timestamp representing the expiration
     */
    public function getExpirationTimestamp(): int
    {
        return strtotime('+3 years');
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

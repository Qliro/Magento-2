<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Logger;

use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Model\Config;

/**
 * The credentials of every store, so the log can mask them by value and not only by key name
 *
 * Masking by key name holds only as long as every call site names its keys the way the redaction
 * expects. Two call sites did not, and wrote the API key out under `configured` and `merchant`.
 */
class SecretProvider
{
    /**
     * Below this a value is too short to be a credential and too likely to appear by accident
     */
    private const MIN_LENGTH = 8;

    /**
     * How long a read that came back with nothing is left alone before it is tried again
     *
     * `getSecrets()` is asked for every value of every log line, so an unconfigured store would
     * otherwise walk the store list that often. A cron that outlives its own start up still gets
     * the credentials once they can be read, which an attempt count would have denied it
     */
    private const RETRY_AFTER_SECONDS = 60;

    /**
     * @var string[]|null
     */
    private ?array $secrets = null;

    /**
     * @var int
     */
    private int $triedAt = 0;

    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Config                $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The credential values to mask wherever they turn up, longest first
     *
     * Every store's own, because a log line does not say which store it belongs to and the cron
     * that emulates each store in turn would otherwise be masked against the first one only.
     *
     * @return string[]
     */
    public function getSecrets(): array
    {
        if ($this->secrets !== null) {
            return $this->secrets;
        }

        if ($this->triedAt !== 0 && time() - $this->triedAt < self::RETRY_AFTER_SECONDS) {
            return [];
        }

        $this->triedAt = time();

        // Set before the read: if anything on the way to the configuration logs, the processor asks
        // this again and has to get an answer rather than recurse
        $this->secrets = [];
        $secrets = [];

        try {
            foreach ($this->getStoreIds() as $storeId) {
                $secrets[] = (string)$this->config->getMerchantApiKey($storeId);
                $secrets[] = (string)$this->config->getMerchantApiSecret($storeId);
            }
        } catch (\Throwable $exception) {
            // Logging must not fail because the configuration could not be read
            $this->secrets = null;

            return [];
        }

        $secrets = array_values(array_unique(array_filter(
            array_map('trim', $secrets),
            static fn(string $secret): bool => strlen($secret) >= self::MIN_LENGTH
        )));

        // Longest first, so a secret that contains another does not leave the shorter one behind
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        if ($secrets === []) {
            // A read that found nothing is not remembered: a store mid setup gets a key later, and
            // neither is a read that failed above. The next line asks again
            $this->secrets = null;

            return [];
        }

        return $this->secrets = $secrets;
    }

    /**
     * @return int[]
     */
    private function getStoreIds(): array
    {
        $storeIds = [];

        foreach ($this->storeManager->getStores() as $store) {
            $storeIds[] = (int)$store->getId();
        }

        return $storeIds ?: [0];
    }
}

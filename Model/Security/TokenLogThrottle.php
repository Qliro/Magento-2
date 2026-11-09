<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Security;

use Magento\Framework\App\CacheInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Lets one token log line of a kind through per store and hour
 *
 * The callback endpoints are open to anyone, so a line above debug must not be one a caller can
 * repeat into the log at will.
 */
class TokenLogThrottle
{
    public const WINDOW_SECONDS = 3600;
    public const CACHE_TAG = 'QLIROONE_TOKEN_LOG';
    private const CACHE_PREFIX = 'qliroone_token_log_';
    private const VARIANTS = 5;

    /**
     * @param CacheInterface $cache
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private CacheInterface $cache,
        private StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether this line is the first of its kind and variant in the window
     *
     * A kind lets at most a few variants through, so a caller cannot widen it by varying them.
     * A reserved variant has its own line outside that count.
     *
     * @param string $kind
     * @param string $variant
     * @param bool $reserved
     * @return bool
     */
    public function allow(string $kind, string $variant = '', bool $reserved = false): bool
    {
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
        } catch (\Throwable $exception) {
            $storeId = 0;
        }

        $prefix = self::CACHE_PREFIX . preg_replace('/\W+/', '_', $kind) . '_' . $storeId;
        $lineKey = $prefix . '_' . preg_replace('/\W+/', '_', $variant);

        try {
            if ($this->cache->load($lineKey)) {
                return false;
            }

            if (!$reserved) {
                // "count:started", so a new variant does not push the end of the window out
                [$count, $started] = array_map('intval', explode(':', (string)$this->cache->load($prefix) . ':'));
                $left = $started + self::WINDOW_SECONDS - time();

                if ($left <= 0) {
                    [$count, $started, $left] = [0, time(), self::WINDOW_SECONDS];
                }

                if ($count >= self::VARIANTS) {
                    return false;
                }

                $this->cache->save(($count + 1) . ':' . $started, $prefix, [self::CACHE_TAG], $left);
            }

            $this->cache->save('1', $lineKey, [self::CACHE_TAG], self::WINDOW_SECONDS);
        } catch (\Throwable $exception) {
            // A cache outage must not hide the line
            return true;
        }

        return true;
    }
}

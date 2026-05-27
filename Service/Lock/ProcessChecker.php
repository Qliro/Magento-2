<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Service\Lock;

use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Checks whether a locking process is still alive and whether a lock timestamp has expired.
 */
readonly class ProcessChecker
{
    /**
     * Class constructor
     *
     * @param DateTime $dateTime
     */
    public function __construct(
        private DateTime $dateTime
    ) {
    }

    /**
     * Return the PID of the current PHP process.
     *
     * @return int|false
     */
    public function getCurrentPid(): int|false
    {
        return \getmypid();
    }

    /**
     * Check whether the process identified by $pid is still running.
     * Works only for processes owned by the same OS user.
     *
     * @param mixed $pid
     * @return bool
     */
    public function isAlive(mixed $pid): bool
    {
        if (!is_numeric($pid)) {
            return false;
        }

        $pid = (int)$pid;

        if (\function_exists('posix_getpgid')) {
            return \posix_getpgid($pid) !== false;
        }

        if (\function_exists('posix_kill')) {
            return \posix_kill($pid, 0);
        }

        if (defined('PHP_OS') && PHP_OS === 'Linux') {
            return \file_exists("/proc/$pid");
        }

        return \shell_exec(sprintf('ps -p %s | wc -l', $pid)) > 1;
    }

    /**
     * Check whether more than $ttlSeconds have passed since the given GMT date string.
     *
     * @param string $date       GMT date string as stored in the database
     * @param int    $ttlSeconds Lock time-to-live in seconds (default 10)
     * @return bool
     */
    public function isLockExpired(string $date, int $ttlSeconds = 10): bool
    {
        $savedTimestamp   = strtotime($date);
        $currentTimestamp = $this->dateTime->gmtTimestamp();

        return ($currentTimestamp - $savedTimestamp) > $ttlSeconds;
    }
}

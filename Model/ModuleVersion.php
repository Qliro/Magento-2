<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model;

/**
 * The version of this module, read from its own composer.json, which is where every release sets it
 */
class ModuleVersion
{
    public const UNKNOWN = 'unknown';

    private static ?string $version = null;

    /**
     * @return string
     */
    public static function get(): string
    {
        if (self::$version === null) {
            self::$version = self::read(dirname(__DIR__) . '/composer.json');
        }

        return self::$version;
    }

    /**
     * @param string $path
     * @return string
     */
    public static function read(string $path): string
    {
        // A header must not cost a request its life, so anything unreadable is reported as unknown
        $contents = is_readable($path) ? file_get_contents($path) : false;
        $data = is_string($contents) ? json_decode($contents, true) : null;
        $version = is_array($data) ? ($data['version'] ?? null) : null;

        return is_string($version) && preg_match('/^[0-9A-Za-z.\-+]{1,32}$/', $version) ? $version : self::UNKNOWN;
    }
}

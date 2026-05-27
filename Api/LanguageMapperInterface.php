<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

/**
 * Language Mapper interface
 *
 * Converts the current Magento locale into a language code supported by QliroOne.
 *
 * @api
 */
interface LanguageMapperInterface
{
    /**
     * Get a QliroOne-compatible language code for the current locale
     *
     * @return string
     */
    public function getLanguage(): string;
}

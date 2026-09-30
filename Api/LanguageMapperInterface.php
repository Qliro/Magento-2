<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

/**
 * Language Mapper interface.
 * Converts current Magento locale into language code supported by QliroOne order
 *
 * @api
 */
interface LanguageMapperInterface
{
    /**
     * Get a prepared string that contains a QliroOne compatible language
     *
     * @param int|null $storeId
     * @return string
     */
    public function getLanguage($storeId = null);
}

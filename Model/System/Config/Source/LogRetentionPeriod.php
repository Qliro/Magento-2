<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\System\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for a log retention period select the field
 */
class LogRetentionPeriod implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 7,   'label' => __('7 days')],
            ['value' => 14,  'label' => __('14 days')],
            ['value' => 30,  'label' => __('30 days (1 month)')],
            ['value' => 60,  'label' => __('60 days (2 months)')],
            ['value' => 90,  'label' => __('90 days (3 months)')],
            ['value' => 180, 'label' => __('180 days (6 months)')],
        ];
    }
}

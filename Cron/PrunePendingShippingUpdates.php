<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Cron;

use Qliro\QliroOne\Model\Order\ShippingProviderUpdate;

/**
 * Drops shipping provider updates of orders never placed, see etc/crontab.xml
 */
class PrunePendingShippingUpdates
{
    private const KEEP_DAYS = 30;

    /**
     * @param ShippingProviderUpdate $shippingProviderUpdate
     */
    public function __construct(
        private readonly ShippingProviderUpdate $shippingProviderUpdate
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->shippingProviderUpdate->prune(self::KEEP_DAYS);
    }
}

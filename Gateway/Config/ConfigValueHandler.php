<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Gateway\Config;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Config\ValueHandlerInterface;

class ConfigValueHandler implements ValueHandlerInterface
{
    /**
     * Class constructor
     *
     * @param ConfigInterface $configInterface
     */
    public function __construct(
        private readonly ConfigInterface $configInterface
    ) {
    }

    /**
     * Retrieve method configured value
     *
     * @param array $subject
     * @param int|string|null $storeId
     * @return mixed
     */
    public function handle(array $subject, $storeId = null): mixed
    {
        return $this->configInterface->getValue(SubjectReader::readField($subject), $storeId);
    }
}

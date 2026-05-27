<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Info;

use Qliro\QliroOne\Model\Config;

abstract class AbstractInfo extends \Magento\Payment\Block\Info
{
    /**
     * @var bool|null
     */
    private ?bool $warning = null;

    /**
     * @var string|null
     */
    private ?string $warningText = null;

    /**
     * Fetch received WarningText
     *
     * @return string|null
     */
    public function getWarningText(): ?string
    {
        if ($this->warningText === null) {
            $this->convertAdditionalInformation();
        }
        return $this->warningText;
    }

    /**
     * If a warning is due
     *
     * @return bool|null
     */
    public function showWarning(): ?bool
    {
        if ($this->warning === null) {
            $this->convertAdditionalInformation();
        }
        return $this->warning;
    }

    /**
     * Takes specific data from AdditionalInformation field and make it available for FE
     *
     * @return static
     */
    private function convertAdditionalInformation(): static
    {
        return $this;
    }
}

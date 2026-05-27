<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Html\Select;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingConfigUnifaunBuilder;

/**
 * Class FunctionColumn
 */
class FunctionColumn extends Select
{
    /**
     * Set "name" for a <select> element
     *
     * @param string $value
     * @return static
     */
    public function setInputName(string $value): static
    {
        return $this->setName($value);
    }

    /**
     * Set "id" for a <select> element
     *
     * @param string $value
     * @return static
     */
    public function setInputId(string $value): static
    {
        return $this->setId($value);
    }

    /**
     * Render block HTML
     *
     * @return string
     */
    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->getSourceOptions());
        }
        return parent::_toHtml();
    }

    /**
     * @return array[]
     */
    private function getSourceOptions(): array
    {
        return [
            ['label' => \__('Bulky'), 'value' => ShippingConfigUnifaunBuilder::UNIFAUN_TAGS_FUNC_BULKY],
            ['label' => \__('Cart Price'), 'value' => ShippingConfigUnifaunBuilder::UNIFAUN_TAGS_FUNC_CARTPRICE],
            ['label' => \__('User Defined'), 'value' => ShippingConfigUnifaunBuilder::UNIFAUN_TAGS_FUNC_USERDEFINED],
            ['label' => \__('Weight'), 'value' => ShippingConfigUnifaunBuilder::UNIFAUN_TAGS_FUNC_WEIGHT],
        ];
    }
}

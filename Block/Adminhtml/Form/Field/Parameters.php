<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingConfigUnifaunBuilder;

/**
 * Class Ranges
 */
class Parameters extends AbstractFieldArray
{
    /**
     * @var FunctionColumn
     */
    private $functionRenderer;

    /**
     * Prepare rendering the new field by adding all the needed columns
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     */
    // phpcs:ignore VCQP.PHP.ProtectedClassMember.FoundProtected
    // phpcs:ignore VCQP.PHP.ProtectedClassMember.FoundProtected,VCQP.Methods.MethodDeclaration.Underscore
    // phpcs:disable CODOR.Classes.PropertyDeclaration.Missing
    protected function _prepareToRender()
    {
        $this->addColumn(
            ShippingConfigUnifaunBuilder::UNIFAUN_TAGS_SETTING_TAG,
            ['label' => \__('Tag'), 'class' => 'required-entry']
        );
        $this->addColumn(
            ShippingConfigUnifaunBuilder::UNIFAUN_TAGS_SETTING_FUNC,
            ['label' => \__('Function'), 'size' => '60', 'renderer' => $this->getFunctionRenderer()]
        );
        $this->addColumn(
            ShippingConfigUnifaunBuilder::UNIFAUN_TAGS_SETTING_VALUE,
            ['label' => \__('Value')]
        );
        $this->_addAfter = false;
        $this->_addButtonLabel = \__('Add');
    }

    /**
     * Prepare existing row data object
     *
     * @param DataObject $row
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];

        $function = $row->getFunc();
        if ($function !== null) {
            $options['option_' . $this->getFunctionRenderer()->calcOptionHash($function)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * @return FunctionColumn
     * @throws LocalizedException
     */
    private function getFunctionRenderer()
    {
        if (!$this->functionRenderer) {
            $this->functionRenderer = $this->getLayout()->createBlock(
                FunctionColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->functionRenderer;
    }
}

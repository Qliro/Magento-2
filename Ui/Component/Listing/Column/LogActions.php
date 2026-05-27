<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface as Url;
use Magento\Framework\View\Element\UiComponent\ContextInterface as Context;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Adds a "View" action link to each row of the QliroOne log grid.
 */
class LogActions extends Column
{
    /**
     * Class constructor
     *
     * @param Context            $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Url                $urlBuilder
     * @param array              $components
     * @param array              $data
     */
    public function __construct(
        Context              $context,
        UiComponentFactory   $uiComponentFactory,
        private readonly Url $urlBuilder,
        array                $components = [],
        array                $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $item[$this->getData('name')] = [
                'view' => [
                    'href'  => $this->urlBuilder->getUrl('qliroone/log/view', ['id' => $item['id']]),
                    'label' => __('View'),
                ],
            ];
        }

        return $dataSource;
    }
}

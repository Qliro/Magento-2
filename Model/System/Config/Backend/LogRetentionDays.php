<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\System\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Qliro\QliroOne\Model\Config;

/**
 * Guards the Log Retention field, which `bin/magento config:set` reaches without the admin form
 */
class LogRetentionDays extends Value
{
    /**
     * @var Config
     */
    private $qliroConfig;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @param Config|null $qliroConfig
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?Config $qliroConfig = null
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);

        // Optional so the parent signature keeps working, Magento passes null for optional arguments
        $this->qliroConfig = $qliroConfig ?: ObjectManager::getInstance()->get(Config::class);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $scope = $this->getScope();

        // The pruner reads the default scope only, so a value saved anywhere else would never be read
        if ($scope && $scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            throw new LocalizedException(
                __('Log Retention is a single setting for the whole log table and can only be set on the default scope.')
            );
        }

        $days = $this->qliroConfig->parseLogRetentionDays($this->getValue());

        if ($days === null) {
            throw new LocalizedException(
                __('Log Retention must be a whole number of days between 0 and %1.', Config::MAX_LOG_RETENTION_DAYS)
            );
        }

        $this->setValue((string)$days);

        return parent::beforeSave();
    }
}

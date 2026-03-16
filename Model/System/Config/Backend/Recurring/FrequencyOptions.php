<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\System\Config\Backend\Recurring;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Frequency options config backend model
 */
class FrequencyOptions extends Value
{
    /**
     * Class constructor
     *
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param Json $serializer
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        private readonly Json $serializer,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $value = $this->getValue();
        $value = $this->serializer->serialize($value);
        $this->setValue($value);
        return parent::beforeSave();
    }

    protected function _afterLoad()
    {
        $value = $this->getValue();
        if (!$value) {
            return;
        }
        $value = $this->serializer->unserialize($value);
        unset($value['__empty']);
        $this->setValue($value);
    }
}

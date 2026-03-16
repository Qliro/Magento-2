<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model;

use Qliro\QliroOne\Api\Data\RecurringInfoInterface;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;
use Qliro\QliroOne\Model\RecurringInfoFactory as ModelFactory;
use Qliro\QliroOne\Model\ResourceModel\RecurringInfoFactory as ResourceFactory;
use Qliro\QliroOne\Model\ResourceModel\RecurringInfo\CollectionFactory;

class RecurringInfoRepository implements RecurringInfoRepositoryInterface
{
    /**
     * Class constructor
     *
     * @param ModelFactory $modelFactory
     * @param ResourceFactory $resourceFactory
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly ModelFactory $modelFactory,
        private readonly ResourceFactory $resourceFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(RecurringInfoInterface $recurringInfo)
    {
        $resourceModel = $this->resourceFactory->create();
        $resourceModel->save($recurringInfo);
    }

    /**
     * @inheritDoc
     */
    public function getByOriginalOrderId(int $orderId): RecurringInfoInterface
    {
        return $this->load('original_order_id', $orderId);
    }

    /**
     * @inheritDoc
     */
    public function getByRecurringToken(string $recurringToken): RecurringInfoInterface
    {
        return $this->load('recurring_token', $recurringToken);
    }

    /**
     * Load an instance
     *
     * @param string $field
     * @param mixed $value
     * @return RecurringInfoInterface
     */
    private function load(string $field, mixed $value): RecurringInfoInterface
    {
        $model = $this->modelFactory->create();
        $resourceModel = $this->resourceFactory->create();
        $resourceModel->load($model, $value, $field);
        return $model;
    }

    /**
     * @inheritDoc
     */
    public function getByTodaysDate(?int $storeId = null): array
    {
        $todaysDate = date('Y-m-d');
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('next_order_date', ['from' => $todaysDate, 'to' => $todaysDate]);
        $collection->join(
            'sales_order',
            'main_table.original_order_id = sales_order.entity_id',
            ['store_id']
        );

        if (null !== $storeId) {
            $collection->addFieldToFilter('sales_order.store_id', $storeId);
        }

        return $collection->getItems();
    }
}

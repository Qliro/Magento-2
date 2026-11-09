<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class RecurringInfo extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('qliroone_recurring_info', 'entity_id');
    }

    /**
     * Moves the next order date of a subscription that is still active
     *
     * A cancel that lands while the cron places the order is kept: a save of the model the cron
     * loaded would write the cancel date and the card removal back to empty
     *
     * @param int $entityId
     * @param string|null $nextOrderDate
     * @return int Rows changed
     */
    public function updateNextOrderDate(int $entityId, ?string $nextOrderDate): int
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            ['next_order_date' => $nextOrderDate],
            ['entity_id = ?' => $entityId, 'canceled_date IS NULL']
        );
    }

    /**
     * Records when Qliro deleted the saved card, once
     *
     * @param int $entityId
     * @param string $removedAt
     * @return int Rows changed, 0 when another request recorded it first
     */
    public function markSavedCardRemoved(int $entityId, string $removedAt): int
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            ['saved_credit_card_removed_at' => $removedAt],
            ['entity_id = ?' => $entityId, 'saved_credit_card_removed_at IS NULL']
        );
    }
}

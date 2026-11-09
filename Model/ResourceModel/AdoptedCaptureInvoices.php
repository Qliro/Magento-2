<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Invoice;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface as Status;
use Qliro\QliroOne\Model\Management\Payment;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus as StatusResource;

/**
 * Orders with an invoice whose transaction came from a capture Qliro refused as already shipped
 *
 * That transaction is the one the module's status row records, so the order is found without
 * asking Qliro.
 */
class AdoptedCaptureInvoices
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Order ids, ascending from the cursor
     *
     * @param string|null $from Invoices created on or after this UTC time
     * @param string|null $to Invoices created before this UTC time
     * @param string[] $incrementIds
     * @param int $afterOrderId Paging cursor, the last order id of the previous page
     * @param int $limit
     * @return int[]
     */
    public function find(?string $from, ?string $to, array $incrementIds, int $afterOrderId, int $limit): array
    {
        // The default connection, it holds the module's tables too
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from(['invoice' => $this->resource->getTableName('sales_invoice')], ['order_id'])
            ->join(
                ['link' => $this->resource->getTableName('qliroone_link')],
                'link.order_id = invoice.order_id',
                []
            )
            // By the Qliro order: the row's record id was the order before it was the payment
            ->join(
                ['adopted' => $this->resource->getTableName(StatusResource::TABLE_OM_STATUS)],
                // An unknown capture is stored as 0, which one of Magento's own text ids reads as
                'adopted.qliro_order_id = link.qliro_order_id AND adopted.transaction_id = invoice.transaction_id'
                . ' AND adopted.transaction_id > 0',
                []
            )
            ->where('adopted.record_type = ?', Status::RECORD_TYPE_PAYMENT)
            ->where('adopted.message LIKE ?', sprintf(Payment::ADOPTED_CAPTURE_MESSAGE, '%'))
            ->where('invoice.state <> ?', Invoice::STATE_CANCELED)
            ->where('invoice.order_id > ?', $afterOrderId)
            ->order('invoice.order_id ASC')
            ->limit($limit);

        if ($from !== null) {
            $select->where('invoice.created_at >= ?', $from);
        }

        if ($to !== null) {
            $select->where('invoice.created_at < ?', $to);
        }

        if ($incrementIds !== []) {
            $select->join(
                ['sales_order' => $this->resource->getTableName('sales_order')],
                'sales_order.entity_id = invoice.order_id',
                []
            )->where('sales_order.increment_id IN (?)', $incrementIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationInterface;

/**
 * Qliro's shipping provider update on an order, kept against the link while the order is not placed
 */
class ShippingProviderUpdate
{
    public const TABLE = 'qliroone_pending_shipping_update';
    public const INFO_KEY = 'qliroone_shipping_info';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var Json
     */
    private Json $json;

    /**
     * @param ResourceConnection $resource
     * @param Json $json
     */
    public function __construct(
        ResourceConnection $resource,
        Json $json
    ) {
        $this->resource = $resource;
        $this->json = $json;
    }

    /**
     * Put the update on the order, false when the order holds it or a newer one. The order is not saved
     *
     * @param OrderInterface $order
     * @param string $provider
     * @param array $payload
     * @param string $sentAt Qliro's Timestamp, kept on its redeliveries
     * @return bool
     */
    public function apply(OrderInterface $order, string $provider, array $payload, string $sentAt = ''): bool
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $order->getPayment();
        $additionalInfo = (array)$payment->getAdditionalInformation();
        $shippingInfo = $additionalInfo[self::INFO_KEY] ?? [];
        $shippingInfo = is_array($shippingInfo) ? $shippingInfo : [];

        $heldAt = (string)($shippingInfo['sent_at'] ?? '');

        // A redelivery of an older update must not replace a newer one
        if ($this->isOlder($sentAt, $heldAt)) {
            return false;
        }

        // An update held from before times were kept takes the time of its first redelivery,
        // otherwise an older one redelivered after it would replace it
        $newer = $this->isOlder($heldAt, $sentAt) || ($heldAt === '' && $sentAt !== '');
        if (isset($shippingInfo['payload']) && $shippingInfo['payload'] == $payload) {
            // The same choice sent again later still moves the time on
            if ($newer) {
                $shippingInfo['sent_at'] = $sentAt;
                $additionalInfo[self::INFO_KEY] = $shippingInfo;
                $payment->setAdditionalInformation($additionalInfo);
            }

            return $newer;
        }

        $shippingInfo = array_merge($shippingInfo, ['provider' => $provider, 'payload' => $payload]);
        if ($sentAt !== '') {
            $shippingInfo['sent_at'] = $sentAt;
        }
        $additionalInfo[self::INFO_KEY] = $shippingInfo;
        $payment->setAdditionalInformation($additionalInfo);

        $description = $this->describe($provider, $payload);
        if ($description !== null) {
            $order->setShippingDescription($description);
        }

        return true;
    }

    /**
     * Keep the update until the order of the link is placed, a newer update replaces it
     *
     * @param int $linkId
     * @param string $provider
     * @param array $payload
     * @param string $sentAt
     * @return bool False when a newer one is kept
     */
    public function store(int $linkId, string $provider, array $payload, string $sentAt = ''): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $storedAt = (string)$connection->fetchOne(
            $connection->select()->from($table, ['sent_at'])->where('link_id = ?', $linkId)
        );

        if ($this->isOlder($sentAt, $storedAt)) {
            return false;
        }

        $connection->insertOnDuplicate(
            $table,
            [
                'link_id' => $linkId,
                'provider' => $provider,
                'payload' => $this->json->serialize($payload),
                'sent_at' => $sentAt !== '' ? $sentAt : $storedAt,
                'created_at' => new \Zend_Db_Expr('NOW()'),
            ],
            ['provider', 'payload', 'sent_at', 'created_at']
        );

        return true;
    }

    /**
     * Put the update kept for the link on the order, false when none is kept. The order is not saved
     *
     * @param OrderInterface $order
     * @param int $linkId
     * @return bool
     * @throws \InvalidArgumentException When the kept update is unreadable, it is dropped
     */
    public function applyStored(OrderInterface $order, int $linkId): bool
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['provider', 'payload', 'sent_at'])
                ->where('link_id = ?', $linkId)
        );

        if (!$row) {
            return false;
        }

        try {
            $payload = $this->json->unserialize($row['payload']);
        } catch (\InvalidArgumentException $exception) {
            $payload = null;
        }

        if (!is_array($payload)) {
            $this->discard($linkId);
            throw new \InvalidArgumentException('The stored Shipping Provider Update is unreadable, it was dropped');
        }

        $this->apply($order, (string)$row['provider'], $payload, (string)$row['sent_at']);

        return true;
    }

    /**
     * Drop the update kept for the link
     *
     * @param int $linkId
     * @return void
     */
    public function discard(int $linkId): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['link_id = ?' => $linkId]
        );
    }

    /**
     * Drop updates kept longer than the days given, their orders were never placed
     *
     * @param int $days
     * @return int
     */
    public function prune(int $days): int
    {
        return $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['created_at < ?' => new \Zend_Db_Expr(sprintf('NOW() - INTERVAL %d DAY', $days))]
        );
    }

    /**
     * Whether Qliro sent the first before the second, false when either has no readable time
     *
     * @param string $sentAt
     * @param string $than
     * @return bool
     */
    private function isOlder(string $sentAt, string $than): bool
    {
        try {
            return $sentAt !== '' && $than !== ''
                && new \DateTimeImmutable($sentAt) < new \DateTimeImmutable($than);
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * @param string $provider
     * @param array $payload
     * @return string|null
     */
    private function describe(string $provider, array $payload): ?string
    {
        $code = strtolower($provider);
        if ($code === MerchantNotificationInterface::PROVIDER_NSHIFT) {
            $name = $payload['service']['name'] ?? null;
            $id = $payload['service']['id'] ?? null;
        } elseif ($code === MerchantNotificationInterface::PROVIDER_INGRID) {
            $shipping = $payload['session']['delivery_groups'][0]['shipping'] ?? [];
            $name = $shipping['carrier'] ?? null;
            $id = $shipping['carrier_product_id'] ?? null;
        } else {
            return null;
        }

        if (!is_scalar($name) || !is_scalar($id)) {
            return null;
        }

        return sprintf('%s - %s (%s)', $provider, $name, $id);
    }
}

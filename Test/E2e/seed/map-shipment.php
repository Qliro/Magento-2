<?php
/**
 * Maps a payload into a new Qliro shipment with the store's own container mapper and prints what it got.
 *
 * Usage, inside the Magento container:
 *   php var/map-shipment.php <base64 JSON payload>
 *
 * Prints one JSON object: whether it got a shipment, whether each item is an order item, the items of a
 * shipment made without a payload and the shipment mapped back to an array.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterface;
use Qliro\QliroOne\Model\ContainerMapper;

require '/var/www/html/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$payload = json_decode(base64_decode($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$mapper = $om->get(ContainerMapper::class);
$shipment = $mapper->fromArray($payload, QliroShipmentInterface::class);

echo json_encode([
    'shipment' => $shipment instanceof QliroShipmentInterface,
    'items' => array_map(fn ($item) => $item instanceof QliroOrderItemInterface, $shipment->getOrderItems()),
    'empty' => $om->create(QliroShipmentInterface::class)->getOrderItems(),
    'array' => $mapper->toArray($shipment),
], JSON_THROW_ON_ERROR), PHP_EOL;

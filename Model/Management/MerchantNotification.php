<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * Merchant Notification management class
 */
class MerchantNotification
{
    /**
     * @var array|null
     */
    private ?array $logContext = null;

    /**
     * @var array|null
     */
    private ?array $response = null;

    /**
     * Class constructor
     *
     * @param LinkRepositoryInterface $linkRepo
     * @param OrderRepositoryInterface $orderRepo
     * @param Manager $logManager
     * @param Lock $lock
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepo,
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly Manager $logManager,
        private readonly Lock $lock,
    ) {
    }

    /**
     * @param MerchantNotificationInterface $container
     * @return MerchantNotificationResponseInterface
     */
    public function execute(array $container): array
    {
        $this->logManager->setMerchantReference($container['MerchantReference'] ?? null);
        $this->logContext = [
            'extra' => [
                'qliro_order_id' => $container['OrderId'] ?? null,
            ],
        ];

        $eventType = $container['EventType'] ?? null;

        $this->logManager->info('Handling event type: ' . $eventType);
        if ($eventType === 'ShippingProviderUpdate') {
            $this->shippingProviderUpdate($container);
        }

        if ($this->response === null) {
            $this->createResponse('We cannot handle this event type', 400);
        }

        return $this->response;
    }

    /**
     * Handler for Event type: SHIPPING_PROVIDER_UPDATE
     *
     * @param MerchantNotificationInterface $container
     * @return void
     * @throws \Exception
     */
    private function shippingProviderUpdate(array $container): void
    {
        $qliroOrderId = $container['OrderId'] ?? null;

        try {
            $link = $this->linkRepo->getByQliroOrderId($qliroOrderId);
        } catch (NoSuchEntityException $e) {
            $this->logManager->critical('Link missing', $this->logContext);
            $this->createResponse('Qliro Link not found', 500);
            return;
        }

        if (!$link->getOrderId()) {
            $this->logManager->notice(
                'MerchantNotification received too early, responding with order not found',
                $this->logContext
            );
            $this->createResponse('Magento Order not created yet, try again later', 404);
            return;
        }

        if (!$this->lock->lock($qliroOrderId)) {
            $this->logManager->warning(
                'MerchantNotification: could not acquire lock — concurrent request in progress.',
                $this->logContext
            );
            $this->createResponse('Order locked by concurrent request, try again later', 500);
            return;
        }

        try {
            try {
                $order = $this->orderRepo->get($link->getOrderId());
            } catch (\Exception $e) {
                $this->logManager->critical(
                    sprintf('Magento Order with id: [%s] not found for MerchantNotification', $link->getOrderId()),
                    $this->logContext
                );
                $this->createResponse('Magento Order not found', 500);
                return;
            }

            $payment = $order->getPayment();
            $additionalInfo = $payment->getAdditionalInformation();
            $shippingInfo = $additionalInfo['qliroone_shipping_info'] ?? [];

            if (isset($shippingInfo['payload']) && $shippingInfo['payload'] == $container['Payload'] ?? null) {
                $this->createResponse('Shipping Provider Update already handled', 200);
                return;
            }

            $shippingInfo['provider'] = $container['Provider'] ?? null;
            $shippingInfo['payload'] = $container['Payload'] ?? null;
            $additionalInfo['qliroone_shipping_info'] = $shippingInfo;
            $payment->setAdditionalInformation($additionalInfo);
            if ($shippingInfo) {
                if ($shippingInfo['provider'] == 'Unifaun') {
                    $order->setShippingDescription($shippingInfo['provider'] . ' - ' . $shippingInfo["payload"]["service"]["name"] . ' (' . $additionalInfo["qliroone_shipping_info"]["payload"]["service"]["id"] . ')');
                } else if ($shippingInfo['provider'] == 'Ingrid') {
                    $order->setShippingDescription($shippingInfo['provider'] . ' - ' . $shippingInfo["payload"]["session"]["delivery_groups"][0]["shipping"]["carrier"] . ' (' . $shippingInfo["payload"]["session"]["delivery_groups"][0]["shipping"]["carrier_product_id"] . ')');
                }
            }

            try {
                $this->orderRepo->save($order);
            } catch (\Exception $e) {
                $this->logManager->critical($e->getMessage(), $this->logContext);
                $this->createResponse('Failed to update Magento order ', 500);
                return;
            }

            $this->createResponse('Shipping Provider Update handled successfully', 200);
        } finally {
            $this->lock->unlock($qliroOrderId);
        }
    }

    /**
     * @param string $result
     * @param int $statusCode
     * @return MerchantNotificationResponseInterface
     */
    private function createResponse(string $result, int $statusCode): void
    {
        $this->response = ['CallbackResponse' => $result, 'callbackResponseCode' => $statusCode];
    }
}

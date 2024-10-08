<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationResponseInterfaceFactory;
use Qliro\QliroOne\Api\Data\MerchantNotificationResponseInterface;
use Qliro\QliroOne\Model\Logger\Manager;

/**
 * Merchant Notification management class
 */
class MerchantNotification extends AbstractManagement
{
    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepo;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepo;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private Manager $logManager;

    /**
     * @var MerchantNotificationInterfaceFactory
     */
    private MerchantNotificationResponseInterfaceFactory $responseFactory;

    /**
     * @var array|null
     */
    private ?array $logContext = null;

    /**
     * @var MerchantNotificationResponseInterface|null
     */
    private ?MerchantNotificationResponseInterface $response = null;

    public function __construct(
        LinkRepositoryInterface $linkRepo,
        OrderRepositoryInterface $orderRepo,
        Manager $logManager,
        MerchantNotificationResponseInterfaceFactory $responseFactory
    ) {
        $this->linkRepo = $linkRepo;
        $this->orderRepo = $orderRepo;
        $this->logManager = $logManager;
        $this->responseFactory = $responseFactory;
    }

    /**
     * @param MerchantNotificationInterface $container
     * @return MerchantNotificationResponseInterface
     */
    public function execute(MerchantNotificationInterface $container): MerchantNotificationResponseInterface
    {
        $this->logManager->setMerchantReference($container->getMerchantReference());
        $this->logContext = [
            'extra' => [
                'qliro_order_id' => $container->getOrderId(),
            ],
        ];

        $eventType = $container->getEventType();

        $this->logManager->info('Handling event type: ' . $eventType);
        if ($eventType === MerchantNotificationInterface::EVENT_TYPE_SHIPPING_PROVIDER_UPDATE) {
            $this->shippingProviderUpdate($container);
        }

        if (null === $this->response) {
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
    private function shippingProviderUpdate(MerchantNotificationInterface $container): void
    {
        try {
            $link = $this->linkRepo->getByQliroOrderId($container->getOrderId());
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

        if (isset($shippingInfo['payload']) && $shippingInfo['payload'] == $container->getPayload()) {
            $this->createResponse('Shipping Provider Update already handled', 200);
            return;
        }

        $shippingInfo['provider'] = $container->getProvider();
        $shippingInfo['payload'] = $container->getPayload();
        $additionalInfo['qliroone_shipping_info'] = $shippingInfo;
        $payment->setAdditionalInformation($additionalInfo);
        if ($shippingInfo) {
            $order->setShippingDescription($shippingInfo['provider'] . ' - ' . $shippingInfo["payload"]["service"]["name"] . ' (' . $additionalInfo["qliroone_shipping_info"]["payload"]["service"]["id"] . ')');
        }

        try {
            $this->orderRepo->save($order);
        } catch (\Exception $e) {
            $this->logManager->critical(
                $e->getMessage(),
                $this->logContext
            );
            $this->createResponse('Failed to update Magento order ', 500);
            return;
        }

        $this->createResponse('Shipping Provider Update handled successfully', 200);
    }

    /**
     * @param string $result
     * @param int $statusCode
     * @return MerchantNotificationResponseInterface
     */
    private function createResponse(string $result, int $statusCode): void
    {
        $this->response = $this->responseFactory->create()
            ->setCallbackResponse($result)
            ->setCallbackResponseCode($statusCode);
    }
}

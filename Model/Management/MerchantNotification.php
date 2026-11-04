<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationResponseInterfaceFactory;
use Qliro\QliroOne\Api\Data\MerchantNotificationResponseInterface;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Order\ShippingProviderUpdate;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * Merchant Notification management class
 */
class MerchantNotification extends AbstractManagement
{
    private const LOCK_ATTEMPTS = 5;
    private const LOCK_WAIT_MICROSECONDS = 200000;

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

    /**
     * @var ShippingProviderUpdate
     */
    private ShippingProviderUpdate $shippingUpdate;

    /**
     * @var Lock
     */
    private Lock $lock;

    /**
     * @param LinkRepositoryInterface $linkRepo
     * @param OrderRepositoryInterface $orderRepo
     * @param Manager $logManager
     * @param MerchantNotificationResponseInterfaceFactory $responseFactory
     * @param ShippingProviderUpdate $shippingUpdate
     * @param Lock $lock
     */
    public function __construct(
        LinkRepositoryInterface $linkRepo,
        OrderRepositoryInterface $orderRepo,
        Manager $logManager,
        MerchantNotificationResponseInterfaceFactory $responseFactory,
        ShippingProviderUpdate $shippingUpdate,
        Lock $lock
    ) {
        $this->linkRepo = $linkRepo;
        $this->orderRepo = $orderRepo;
        $this->logManager = $logManager;
        $this->responseFactory = $responseFactory;
        $this->shippingUpdate = $shippingUpdate;
        $this->lock = $lock;
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
     * Handler for Event type: SHIPPING_PROVIDER_UPDATE.
     * Under the placement lock, so the update is either applied or stored for the placement to apply
     *
     * @param MerchantNotificationInterface $container
     * @return void
     */
    private function shippingProviderUpdate(MerchantNotificationInterface $container): void
    {
        $qliroOrderId = (int)$container->getOrderId();

        try {
            $locked = $this->lockShortly($qliroOrderId);
        } catch (\Throwable $e) {
            $this->logManager->critical($e->getMessage(), $this->logContext);
            $this->createResponse('Could not lock the Qliro order, try again later', 500);
            return;
        }

        if (!$locked) {
            $this->logManager->notice('Qliro order is locked, responding with try again', $this->logContext);
            $this->createResponse('Magento Order is being placed, try again later', 409);
            return;
        }

        try {
            $this->applyOrStore($container);
        } finally {
            $this->lock->unlock($qliroOrderId);
        }
    }

    /**
     * @param MerchantNotificationInterface $container
     * @return void
     */
    private function applyOrStore(MerchantNotificationInterface $container): void
    {
        try {
            $link = $this->findLink((int)$container->getOrderId());
        } catch (NoSuchEntityException $e) {
            $this->logManager->critical('Link missing', $this->logContext);
            $this->createResponse('Qliro Link not found', 500);
            return;
        }

        if (!$link->getOrderId()) {
            try {
                $kept = $this->shippingUpdate->store(
                    (int)$link->getId(),
                    $container->getProvider(),
                    $container->getPayload(),
                    $container->getTimestamp()
                );
            } catch (\Throwable $e) {
                $this->logManager->critical($e->getMessage(), $this->logContext);
                $this->createResponse('Failed to store Shipping Provider Update', 500);
                return;
            }

            $this->logManager->notice(
                $kept
                    ? 'Shipping Provider Update stored until the order is placed'
                    : 'Shipping Provider Update older than the stored one ignored',
                $this->logContext
            );
            $this->createResponse(MerchantNotificationResponseInterface::RESPONSE_RECEIVED, 200);
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

        if ($order->getState() === \Magento\Sales\Model\Order::STATE_CANCELED) {
            // Refused or canceled, it ships nothing
            $this->logManager->notice('Shipping Provider Update for a canceled order ignored', $this->logContext);
            try {
                $this->shippingUpdate->discard((int)$link->getId());
            } catch (\Throwable $e) {
                $this->logManager->critical('Could not drop the stored update: ' . $e->getMessage(), $this->logContext);
            }
            $this->createResponse(MerchantNotificationResponseInterface::RESPONSE_RECEIVED, 200);
            return;
        }

        try {
            // One kept from before placement may be newer than this one
            $stored = $this->shippingUpdate->applyStored($order, (int)$link->getId());
        } catch (\Throwable $e) {
            $stored = false;
            $this->logManager->critical($e->getMessage(), $this->logContext);
        }

        try {
            $applied = $this->shippingUpdate->apply(
                $order,
                $container->getProvider(),
                $container->getPayload(),
                $container->getTimestamp()
            );
            if ($stored || $applied) {
                $this->orderRepo->save($order);
            }
        } catch (\Throwable $e) {
            $this->logManager->critical(
                $e->getMessage(),
                $this->logContext
            );
            $this->createResponse('Failed to update Magento order ', 500);
            return;
        }

        try {
            if ($stored) {
                $this->shippingUpdate->discard((int)$link->getId());
            }
        } catch (\Throwable $e) {
            $this->logManager->critical('Could not drop the stored update: ' . $e->getMessage(), $this->logContext);
        }

        $this->logManager->info(
            $applied ? 'Shipping Provider Update applied' : 'Shipping Provider Update already applied',
            $this->logContext
        );
        // Qliro takes only this answer as delivered and sends anything else again
        $this->createResponse(MerchantNotificationResponseInterface::RESPONSE_RECEIVED, 200);
    }

    /**
     * The active link of the Qliro order, else the one a refused or replaced checkout left
     *
     * @param int $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\LinkInterface
     * @throws NoSuchEntityException
     */
    private function findLink(int $qliroOrderId)
    {
        try {
            return $this->linkRepo->getByQliroOrderId($qliroOrderId);
        } catch (NoSuchEntityException $e) {
            return $this->linkRepo->getByQliroOrderId($qliroOrderId, false);
        }
    }

    /**
     * Wait a little for a lock held for a moment, a placement holding it answers try again
     *
     * @param int $qliroOrderId
     * @return bool
     */
    private function lockShortly(int $qliroOrderId): bool
    {
        for ($attempt = 1; !$this->lock->lock($qliroOrderId); $attempt++) {
            if ($attempt >= self::LOCK_ATTEMPTS) {
                return false;
            }
            usleep(self::LOCK_WAIT_MICROSECONDS);
        }

        return true;
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

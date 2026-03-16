<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;

/**
 * QliroOne management class
 */
class SavedCreditCard
{
    const QLIRO_SAVED_CREDIT_CARD_ID_KEY = 'qliro_saved_credit_card_id';

    /**
     * Class constructor
     *
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderRepositoryInterface $orderRepo
     * @param RecurringDataService $recurringDataService
     * @param RecurringInfoRepositoryInterface $recurringInfoRepo
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly RecurringDataService $recurringDataService,
        private readonly RecurringInfoRepositoryInterface $recurringInfoRepo,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Stores saved credit card ID for recurring order
     *
     * @param \Qliro\QliroOne\Api\Data\MerchantSavedCreditCardNotificationInterface $updateContainer
     * @return \Qliro\QliroOne\Api\Data\MerchantSavedCreditCardResponseInterface
     */
    public function update(array $updateContainer): array
    {
        $logContext = [
            'extra' => [
                'qliro_order_id' => $updateContainer['OrderId'] ?? null,
            ],
        ];

        try {
            $link = $this->linkRepository->getByQliroOrderId($updateContainer['OrderId'] ?? null);
            $this->logManager->setMerchantReference($link->getReference());
            if (!$link->getOrderId()) {
                return $this->checkoutStatusRespond(
                    'OrderPending',
                    200
                );
            }
            $order = $this->orderRepo->get($link->getOrderId());
            $recurringInfo = $this->recurringDataService->orderGetter($order);

            if (!$recurringInfo->getEnabled()) {
                return $this->checkoutStatusRespond(
                    'Received',//no action taken on this non-Subscription order send received
                    200
                );
            }

            $recurringInfo->setPaymentMethodMerchantSavedCreditCardId($updateContainer['Id'] ?? null);
            $this->recurringDataService->orderSetter($order, $recurringInfo);
            $this->orderRepo->save($order);

            $recurringInfo = $this->recurringInfoRepo->getByOriginalOrderId($link->getOrderId());
            if (!$recurringInfo->getId()) {
                $this->logManager->notice(
                    'MerchantSavedCreditCardNotification received before recurring info created, responding with order pending',
                    $logContext
                );
                return $this->checkoutStatusRespond(
                    'OrderPending',
                    200
                );
            }

            $recurringInfo->setSavedCreditCardId((string)$updateContainer['Id'] ?? null);
            $this->recurringInfoRepo->save($recurringInfo);

            return $this->checkoutStatusRespond(
                'Received',//Successfully Saved Credit Card ID for order send received
                200
            );
        } catch (NoSuchEntityException $exception) {
            $this->logManager->notice(
                'MerchantSavedCreditCardNotification received before Magento order created, responding with order not found',
                $logContext
            );
            return $this->checkoutStatusRespond(
                'OrderNotFound',
                406
            );
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception->getMessage(),
                $logContext
            );
            return $this->checkoutStatusRespond(
                'CriticalError',
                500
            );
        }
    }

    /**
     * @param string $result
     * @param int $statusCode
     * @return MerchantSavedCreditCardResponseInterface
     */
    private function checkoutStatusRespond(string $result, int $statusCode): array
    {
        return ['CallbackResponse' => $result, 'callbackResponseCode' => $statusCode];
    }
}

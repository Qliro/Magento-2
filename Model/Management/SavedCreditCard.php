<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardResponseInterface;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardResponseInterfaceFactory;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardNotificationInterface ;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;

/**
 * QliroOne management class
 */
class SavedCreditCard extends AbstractManagement
{
    const QLIRO_SAVED_CREDIT_CARD_ID_KEY = 'qliro_saved_credit_card_id';

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepo;

    /**
     * @var \Qliro\QliroOne\Api\Data\MerchantSavedCreditCardResponseInterfaceFactory
     */
    private $savedCreditCardResponseFactory;

    /**
     * @var \Qliro\QliroOne\Service\RecurringPayments\Data
     */
    private $recurringDataService;

    /**
     * @var \Qliro\QliroOne\Api\RecurringInfoRepositoryInterface
     */
    private $recurringInfoRepo;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * Inject dependencies
     *
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderRepositoryInterface $orderRepo
     * @param MerchantSavedCreditCardResponseInterfaceFactory $savedCreditCardResponseFactory
     * @param RecurringDataService $recurringDataService
     * @param RecurringInfoRepositoryInterface $recurringInfoRepo
     * @param LogManager $logManager
     */
    public function __construct(
        LinkRepositoryInterface $linkRepository,
        OrderRepositoryInterface $orderRepo,
        MerchantSavedCreditCardResponseInterfaceFactory $savedCreditCardResponseFactory,
        RecurringDataService $recurringDataService,
        RecurringInfoRepositoryInterface $recurringInfoRepo,
        LogManager $logManager
    ) {
        $this->linkRepository = $linkRepository;
        $this->orderRepo = $orderRepo;
        $this->savedCreditCardResponseFactory = $savedCreditCardResponseFactory;
        $this->recurringDataService = $recurringDataService;
        $this->recurringInfoRepo = $recurringInfoRepo;
        $this->logManager = $logManager;
    }

    /**
     * Stores saved credit card ID for recurring order
     *
     * @param \Qliro\QliroOne\Api\Data\MerchantSavedCreditCardNotificationInterface $updateContainer
     * @return \Qliro\QliroOne\Api\Data\MerchantSavedCreditCardResponseInterface
     */
    public function update(MerchantSavedCreditCardNotificationInterface $updateContainer)
    {
        $logContext = [
            'extra' => [
                'qliro_order_id' => $updateContainer->getOrderId(),
            ],
        ];

        try {
            $link = $this->linkRepository->getByQliroOrderId($updateContainer->getOrderId());
            $this->logManager->setMerchantReference($link->getReference());

            $order = $this->orderRepo->get($link->getOrderId());
            $recurringInfo = $this->recurringDataService->orderGetter($order);

            if (!$recurringInfo->getEnabled()) {
                return $this->checkoutStatusRespond(
                    'MerchantSavedCreditCardNotification received, but no action taken on this non-Subscription order',
                    200
                );
            }

            $recurringInfo->setPaymentMethodMerchantSavedCreditCardId($updateContainer->getId());
            $this->recurringDataService->orderSetter($order, $recurringInfo);
            $this->orderRepo->save($order);

            $recurringInfo = $this->recurringInfoRepo->getByOriginalOrderId($link->getOrderId());
            if (!$recurringInfo->getId()) {
                $recurringInfo->setOriginalOrderId($order->getEntityId());
            }

            $recurringInfo->setSavedCreditCardId((string)$updateContainer->getId());
            $this->recurringInfoRepo->save($recurringInfo);

            return $this->checkoutStatusRespond(
                'Successfully Saved Credit Card ID for order',
                200
            );
        } catch (NoSuchEntityException $exception) {
            $this->logManager->notice(
                'MerchantSavedCreditCardNotification received before Magento order created, responding with order not found',
                $logContext
            );
            return $this->checkoutStatusRespond(
                MerchantSavedCreditCardResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                406
            );
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception->getMessage(),
                $logContext
            );
            return $this->checkoutStatusRespond(
                MerchantSavedCreditCardResponseInterface::RESPONSE_CRITICAL_ERROR,
                500
            );
        }
    }

    /**
     * @param string $result
     * @param int $statusCode
     * @return MerchantSavedCreditCardResponseInterface
     */
    private function checkoutStatusRespond(string $result, int $statusCode): MerchantSavedCreditCardResponseInterface
    {
        return $this->savedCreditCardResponseFactory->create()
            ->setCallbackResponse($result)
            ->setCallbackResponseCode($statusCode);
    }
}

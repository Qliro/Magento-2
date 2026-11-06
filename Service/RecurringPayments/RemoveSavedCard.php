<?php declare(strict_types=1);

namespace Qliro\QliroOne\Service\RecurringPayments;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\RecurringInfoInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\ResourceModel\RecurringInfo as RecurringInfoResource;

/**
 * Deletes a subscription's saved card at Qliro
 *
 * Only a cancelled subscription gives its card up: the next recurring order of an active one
 * would be charged to a card Qliro no longer has.
 */
class RemoveSavedCard
{
    public const REMOVED = 'removed';
    public const NO_SAVED_CARD = 'no_saved_card';
    public const NOT_CANCELED = 'not_canceled';

    private MerchantInterface $merchantApi;

    private RecurringInfoResource $recurringInfoResource;

    private OrderRepositoryInterface $orderRepo;

    private OrderStatusHistoryRepositoryInterface $historyRepo;

    private LogManager $logManager;

    public function __construct(
        MerchantInterface $merchantApi,
        RecurringInfoResource $recurringInfoResource,
        OrderRepositoryInterface $orderRepo,
        OrderStatusHistoryRepositoryInterface $historyRepo,
        LogManager $logManager
    ) {
        $this->merchantApi = $merchantApi;
        $this->recurringInfoResource = $recurringInfoResource;
        $this->orderRepo = $orderRepo;
        $this->historyRepo = $historyRepo;
        $this->logManager = $logManager;
    }

    /**
     * @param RecurringInfoInterface $recurringInfo
     * @return string One of the class constants
     * @throws \Exception When Qliro refuses or cannot be reached
     */
    public function execute(RecurringInfoInterface $recurringInfo): string
    {
        $savedCardId = (string)$recurringInfo->getSavedCreditCardId();
        if ($savedCardId === '') {
            return self::NO_SAVED_CARD;
        }

        if ($recurringInfo->getSavedCreditCardRemovedAt()) {
            return self::REMOVED;
        }

        if (!$recurringInfo->getCanceledDate()) {
            return self::NOT_CANCELED;
        }

        /** @var \Magento\Sales\Model\Order $order */
        $order = $this->orderRepo->get((int)$recurringInfo->getOriginalOrderId());
        // The store's credentials sign the call; the default scope's could belong to another merchant
        if ($order->getStoreId() === null) {
            throw new LocalizedException(__('The subscription order has no store'));
        }

        $qliroHadTheCard = $this->merchantApi->removeSavedCreditCard($savedCardId, (int)$order->getStoreId());

        $removedAt = gmdate('Y-m-d H:i:s');
        $recurringInfo->setSavedCreditCardRemovedAt($removedAt);
        if (!$this->recurringInfoResource->markSavedCardRemoved((int)$recurringInfo->getId(), $removedAt)) {
            return self::REMOVED;
        }

        if (!$qliroHadTheCard) {
            $this->logManager->notice('Qliro had no saved card {card} to remove', ['extra' => [
                'card' => $savedCardId,
                'order_id' => $recurringInfo->getOriginalOrderId(),
            ]]);
        }

        try {
            $history = $order->addCommentToStatusHistory((string)($qliroHadTheCard
                ? __('The customer removed the saved card of this subscription. Qliro deleted it.')
                : __('The customer removed the saved card of this subscription. Qliro no longer had it.')));
            $this->historyRepo->save($history);
        } catch (\Exception $e) {
            // The card is gone either way; a missing comment must not tell the customer otherwise
            $this->logManager->critical($e, ['extra' => ['order_id' => $recurringInfo->getOriginalOrderId()]]);
        }

        return self::REMOVED;
    }
}

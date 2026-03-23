<?php declare(strict_types=1);

namespace Qliro\QliroOne\Service\RecurringPayments;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\Data\PaymentRecurringInfo;
use Qliro\QliroOne\Model\Data\PaymentRecurringInfoFactory;
use Qliro\QliroOne\Api\Data\RecurringInfoInterface;
use Qliro\QliroOne\Model\RecurringInfoFactory;
use Qliro\QliroOne\Model\RecurringInfoRepository;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;

/**
 * Service class with getters and setters for recurring payment data, and useful helper methods
 */
class Data
{
    const RECURRING_PAYMENT_INFO_KEY = 'qliro_recurring_info';

    private PaymentRecurringInfoFactory $paymentRecurringInfoFactory;

    private RecurringInfoFactory $recurringInfoModelFactory;

    private RecurringInfoRepository $recurringInfoRepo;

    private Json $serializer;

    public function __construct(
        PaymentRecurringInfoFactory $paymentRecurringInfoFactory,
        RecurringInfoFactory $recurringInfoModelFactory,
        RecurringInfoRepository $recurringInfoRepo,
        Json $serializer
    ) {
        $this->paymentRecurringInfoFactory = $paymentRecurringInfoFactory;
        $this->recurringInfoModelFactory = $recurringInfoModelFactory;
        $this->recurringInfoRepo = $recurringInfoRepo;
        $this->serializer = $serializer;
    }

    /**
     * Get data object with Recurring Payment Info from quote
     *
     * @param Quote $quote
     * @return PaymentRecurringInfo
     */
    public function quoteGetter(Quote $quote): PaymentRecurringInfo
    {
        $payment = $quote->getPayment();
        $dataArray = $payment->getAdditionalInformation(self::RECURRING_PAYMENT_INFO_KEY);
        $recurringInfo = $this->paymentRecurringInfoFactory->create();
        if (!is_array($dataArray)) {
            return $recurringInfo;
        }

        $recurringInfo->setData($dataArray);
        return $recurringInfo;
    }

    /**
     * Sets Recurring Payment Info to quote
     *
     * @param Quote $quote
     * @param PaymentRecurringInfo $dataObject
     * @return void
     */
    public function quoteSetter(Quote $quote, PaymentRecurringInfo $dataObject): void
    {
        $payment = $quote->getPayment();
        $payment->setAdditionalInformation(self::RECURRING_PAYMENT_INFO_KEY, $dataObject->getData());
    }

    /**
     * Schedules next recurring order using a Quote
     *
     * @param Quote $quote
     * @param string|null $recurringToken
     * @return void
     */
    public function scheduleNextRecurringOrder(Quote $quote): void
    {
        $recurringInfo = $this->quoteGetter($quote);
        $nextOrderDate = $this->getNextOrderDate($recurringInfo->getFrequencyOption());
        $recurringInfo->setNextOrderDate($nextOrderDate);
        $this->quoteSetter($quote, $recurringInfo);
    }

    /**
     * Format recurring frequency options JSON to array of options for a select input
     *
     * @return array
     */
    public function formatRecurringFrequencyOptionsJson(string $json, ?string $selectedFrequency = null): array
    {
        $unserializedValue = $this->serializer->unserialize($json);

        $formattedOptions = [];
        foreach ($unserializedValue as $key => $option) {
            if ($key === '__empty') {
                continue;
            }
            $value = $option['frequency'] . '|' . $option['time_unit'];
            $option = ['label' => $option['label'], 'value' => $value, 'selected' => false];

            if ($selectedFrequency === $value) {
                $option['selected'] = true;
            }
            $formattedOptions[] = $option;
        }
        return $formattedOptions;
    }

    /**
     * @param Order $order
     * @param string|null $personalNumber
     * @return void
     * @throws \Exception
     */
    public function saveNewOrderRecurringInfo(Order $order, ?string $personalNumber = null): void
    {
        $recurringInfoData = $this->orderGetter($order);
        $recurringInfoModel = $this->recurringInfoModelFactory->create();
        $recurringInfoModel->setOriginalOrderId($order->getId());
        $recurringInfoModel->setNextOrderDate($recurringInfoData->getNextOrderDate());
        $recurringInfoModel->setFrequencyOption($recurringInfoData->getFrequencyOption());
        $recurringInfoModel->setPersonalNumber($personalNumber);
        $this->recurringInfoRepo->save($recurringInfoModel);
    }

    /**
     * Get data object with Recurring Payment Info from order
     *
     * @param Order $order
     * @return PaymentRecurringInfo
     */
    public function orderGetter(Order $order): PaymentRecurringInfo
    {
        $payment = $order->getPayment();
        $additionalInfo = $payment->getAdditionalInformation();
        $dataArray = $additionalInfo[self::RECURRING_PAYMENT_INFO_KEY] ?? null;
        $recurringInfo = $this->paymentRecurringInfoFactory->create();
        if (null === $dataArray || !is_array($dataArray)) {
            return $recurringInfo;
        }

        $recurringInfo->setData($dataArray);
        return $recurringInfo;
    }

    /**
     * Sets Recurring Payment Info to Order
     *
     * @param Order $order
     * @param PaymentRecurringInfo $recurringInfo
     */
    public function orderSetter(Order $order, PaymentRecurringInfo $recurringInfo): void
    {
        $payment = $order->getPayment();
        $additionalInfo = $payment->getAdditionalInformation();
        $additionalInfo[self::RECURRING_PAYMENT_INFO_KEY] = $recurringInfo->getData();
        $payment->setAdditionalInformation($additionalInfo);
    }

    /**
     * Sets Canceled data in Recurring Info model
     *
     * @param RecurringInfoInterface $recurringInfo
     * @return void
     */
    public function cancel(RecurringInfoInterface $recurringInfo): void
    {
        $recurringInfo->setCanceledDate(date('Y-m-d'));
        $recurringInfo->setNextOrderDate(null);
    }

    /**
     * Get next order date based on selected frequency option
     *
     * @param RecurringInfo $recurringInfo
     * @return string
     */
    private function getNextOrderDate(string $frequencyOption): string
    {
        $parts = explode('|', $frequencyOption);
        list($frequency, $unit) = $parts;
        if ($frequency > 1) {
            $unit .= 's';
        }

        return date('Y-m-d', strtotime('+' . $frequency . ' ' . $unit));
    }
}

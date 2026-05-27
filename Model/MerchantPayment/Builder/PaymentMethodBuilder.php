<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\MerchantPayment\Builder;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterfaceFactory as PaymentMethodFactory;
use Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterface;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringPaymentsDataService;

class PaymentMethodBuilder
{
    /**
     * @var \Magento\Quote\Model\Quote|null
     */
    private ?Quote $quote = null;

    /**
     * Class constructor
     *
     * @param PaymentMethodFactory $paymentMethodFactory
     * @param RecurringPaymentsDataService $recurringPaymentsDataService
     */
    public function __construct(
        private readonly PaymentMethodFactory $paymentMethodFactory,
        private readonly RecurringPaymentsDataService $recurringPaymentsDataService
    ) {
    }

    /**
     * Set the entity for the builder to use
     *
     * @param Quote $quote
     * @return self
     */
    public function setQuote(Quote $quote): self
    {
        $this->quote = $quote;
        return $this;
    }

    /**
     * Build the Payment Method
     *
     * @return \Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterface
     * @throws \LogicException
     */
    public function create(): MerchantPaymentPaymentMethodInterface
    {
        if (null === $this->quote) {
            throw new \LogicException('Quote not set in Payment Method Builder');
        }

        $quoteRecurringInfo = $this->recurringPaymentsDataService->quoteGetter($this->quote);

        $paymentMethod = $this->paymentMethodFactory->create();

        // We base payment method name / type on whether we have a saved credit card ID
        $paymentMethodName =
            (!!$quoteRecurringInfo->getPaymentMethodMerchantSavedCreditCardId())
            ? MerchantPaymentPaymentMethodInterface::NAME_CREDITCARDS
            : MerchantPaymentPaymentMethodInterface::NAME_INVOICE
        ;

        $paymentMethod->setName($paymentMethodName);

        if ($paymentMethodName === MerchantPaymentPaymentMethodInterface::NAME_INVOICE) {
            $paymentMethod->setSubType(MerchantPaymentPaymentMethodInterface::SUBTYPE_INVOICE);
        }

        if ($paymentMethodName === MerchantPaymentPaymentMethodInterface::NAME_CREDITCARDS) {
            $paymentMethod->setMerchantSavedCreditCardId(
                $quoteRecurringInfo->getPaymentMethodMerchantSavedCreditCardId() ?? ''
            );
        }

        $paymentMethod->setSelectedLetterInvoiceOption(
            $quoteRecurringInfo->getPaymentMethodSelectedLetterInvoiceOption() ?? false
        );

        $this->quote = null;
        return $paymentMethod;
    }
}

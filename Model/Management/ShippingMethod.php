<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote as MagentoQuote;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Payload\PayloadConverter;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromShippingMethodsConverter;

/**
 * Shipping method operations for QliroOne.
 *
 * All methods accept the quote as an explicit parameter where needed.
 */
class ShippingMethod
{
    public function __construct(
        private readonly ShippingMethodsBuilder $shippingMethodsBuilder,
        private readonly QuoteFromShippingMethodsConverter $quoteFromShippingMethodsConverter,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly PayloadConverter $payloadConverter,
        private readonly LogManager $logManager,
        private readonly ManagerInterface $eventManager,
        private readonly Quote $quoteManagement
    ) {
    }

    /**
     * Update quote with received data and return a list of available shipping methods.
     */
    public function get(array $updateContainer): array
    {
        $declineContainer = ['DeclineReason' => 'PostalCode'];

        try {
            $link = $this->linkRepository->getByQliroOrderId($updateContainer['OrderId'] ?? null);
            $this->logManager->setMerchantReference($link->getReference());

            try {
                $quote = $this->quoteRepository->get($link->getQuoteId());
                $this->quoteFromShippingMethodsConverter->convert($updateContainer, $quote);
                $this->quoteManagement->recalculateAndSaveQuote($quote);

                return $this->shippingMethodsBuilder->setQuote($quote)->create();
            } catch (\Exception $exception) {
                $this->logManager->critical($exception, [
                    'extra' => [
                        'qliro_order_id' => $updateContainer['OrderId'] ?? null,
                        'quote_id'       => $link->getQuoteId(),
                    ],
                ]);
                return $declineContainer;
            }
        } catch (\Exception $exception) {
            $this->logManager->critical($exception, [
                'extra' => ['qliro_order_id' => $updateContainer['OrderId'] ?? null],
            ]);
            return $declineContainer;
        }
    }

    /**
     * Update selected shipping method on a quote.
     *
     * Returns true when the method was changed and the quote saved, false otherwise.
     *
     * @throws \Exception
     */
    public function update(MagentoQuote $quote, string $code, ?string $secondaryOption = null, ?float $price = null): bool
    {
        $this->logManager->debug('Starting to update shipping method for quote: ' . $quote->getId());

        if ($code && !$quote->isVirtual()) {
            $this->logManager->debug('Code for quote is: ' . $code);
            $shippingAddress = $quote->getShippingAddress();

            if (!$shippingAddress->getPostcode()) {
                $billingAddress = $quote->getBillingAddress();
                $shippingAddress->addData([
                    'email'      => $billingAddress->getEmail(),
                    'firstname'  => $billingAddress->getFirstname(),
                    'lastname'   => $billingAddress->getLastname(),
                    'company'    => $billingAddress->getCompany(),
                    'street'     => $billingAddress->getStreetFull(),
                    'city'       => $billingAddress->getCity(),
                    'region'     => $billingAddress->getRegion(),
                    'region_id'  => $billingAddress->getRegionId(),
                    'postcode'   => $billingAddress->getPostcode(),
                    'country_id' => $billingAddress->getCountryId(),
                    'telephone'  => $billingAddress->getTelephone(),
                    'same_as_billing' => true,
                ]);
            }

            $container = new DataObject([
                'shipping_method'  => $code,
                'secondary_option' => $secondaryOption,
                'shipping_price'   => $price,
                'can_save_quote'   => $shippingAddress->getShippingMethod() !== $code,
            ]);

            $this->eventManager->dispatch('qliroone_shipping_method_update_before', [
                'quote'     => $quote,
                'container' => $container,
            ]);

            $this->quoteManagement->updateReceivedAmount($quote, $container);

            if (!$container->getCanSaveQuote()) {
                $this->logManager->debug('AJAX:UPDATE_SHIPPING_METHOD: skip reason', [
                    'extra' => [
                        'message'      => 'Shipping method is already set',
                        'quote_method' => $shippingAddress->getShippingMethod(),
                        'qliro_method' => $code,
                    ],
                ]);
                return false;
            }

            $shippingAddress->setShippingMethod($container->getShippingMethod());
            $this->quoteManagement->recalculateAndSaveQuote($quote);

            if ($shippingAddress->getShippingMethod() !== $container->getShippingMethod()) {
                $this->logManager->debug(
                    'Shipping method from quote: ' . $shippingAddress->getShippingMethod() .
                    ' not equal to shipping method from container: ' . $container->getShippingMethod()
                );
                $this->logManager->debug('AJAX:UPDATE_SHIPPING_METHOD: skip reason', [
                    'extra' => [
                        'message'      => 'Unable to change shipping method. Check magento and server logs',
                        'quote_method' => $shippingAddress->getShippingMethod(),
                        'qliro_method' => $code,
                    ],
                ]);
                return false;
            }

            return true;
        }

        return false;
    }
}

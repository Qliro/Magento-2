<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\CartRepositoryInterface as CartRepository;
use Magento\Quote\Model\Quote as MagentoQuote;
use Magento\Quote\Model\QuoteRepository\LoadHandler;
use Qliro\QliroOne\Api\Client\MerchantInterface as Merchant;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface as LinkRepository;
use Qliro\QliroOne\Model\Carrier\Ingrid;
use Qliro\QliroOne\Model\Carrier\Unifaun;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Method\QliroOne;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter;
use Qliro\QliroOne\Service\General\LinkService;

/**
 * Quote operations for QliroOne checkout.
 *
 * All methods accept the quote as an explicit parameter — no mutable
 * setQuote/getQuote state is maintained on this class.
 */
class Quote
{
    /**
     * Class constructor
     *
     * @param Config                          $qliroConfig
     * @param LinkService                     $linkService
     * @param Merchant                        $merchantApi
     * @param CreateRequestBuilder            $createRequestBuilder
     * @param UpdateRequestBuilder            $updateRequestBuilder
     * @param CustomerConverter               $customerConverter
     * @param LinkInterfaceFactory            $linkFactory
     * @param LinkRepository                  $linkRepository
     * @param CartRepository                  $quoteRepository
     * @param LogManager                      $logManager
     * @param RemoteAddress                   $remoteAddress
     * @param EventManager                    $eventManager
     * @param LoadHandler                     $loadHandler
     * @param CountrySelect                   $countrySelectManagement
     */
    public function __construct(
        private readonly Config               $qliroConfig,
        private readonly LinkService          $linkService,
        private readonly Merchant             $merchantApi,
        private readonly CreateRequestBuilder $createRequestBuilder,
        private readonly UpdateRequestBuilder $updateRequestBuilder,
        private readonly CustomerConverter    $customerConverter,
        private readonly LinkInterfaceFactory $linkFactory,
        private readonly LinkRepository       $linkRepository,
        private readonly CartRepository       $quoteRepository,
        private readonly LogManager           $logManager,
        private readonly RemoteAddress        $remoteAddress,
        private readonly EventManager         $eventManager,
        private readonly LoadHandler          $loadHandler,
        private readonly CountrySelect        $countrySelectManagement
    ) {
    }

    /**
     * Recalculate the quote totals, addresses and shipping rates, then save.
     */
    public function recalculateAndSaveQuote(MagentoQuote $quote): void
    {
        $data['method'] = QliroOne::PAYMENT_METHOD_CHECKOUT_CODE;

        $customer        = $quote->getCustomer();
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress  = $quote->getBillingAddress();

        if ($quote->isVirtual()) {
            $billingAddress->setPaymentMethod($data['method']);
        } else {
            $shippingAddress->setPaymentMethod($data['method']);
        }

        $billingAddress->save();

        if (!$quote->isVirtual()) {
            $shippingAddress->save();
        }

        $quote->assignCustomerWithAddressChange($customer, $billingAddress, $shippingAddress);
        $quote->setTotalsCollectedFlag(false);

        if (!$quote->isVirtual()) {
            if (!$shippingAddress->getShippingMethod()) {
                if ($this->qliroConfig->isIngridEnabled($quote->getStoreId())) {
                    if ($this->qliroConfig->isUnifaunEnabled($quote->getStoreId())) {
                        $this->logManager->warning(
                            'Both Unifaun and Ingrid are enabled. Only one widget can run at a time; '
                            . 'using Ingrid and ignoring Unifaun. Disable one of them in config to silence this warning.',
                            ['extra' => ['quote_id' => $quote->getId(), 'store_id' => $quote->getStoreId()]]
                        );
                    }
                    $shippingAddress->setShippingMethod(Ingrid::QLIRO_INGRID_SHIPPING_CODE);
                } elseif ($this->qliroConfig->isUnifaunEnabled($quote->getStoreId())) {
                    $shippingAddress->setShippingMethod(Unifaun::QLIRO_UNIFAUN_SHIPPING_CODE);
                }
            }
            if (!$shippingAddress->hasData('item_qty')) {
                $shippingAddress->setData('item_qty', $quote->getItemsQty());
            }

            $weight = $this->getQuoteItemsWeight($quote);
            $shippingAddress->setWeight($weight);
            $shippingAddress->setFreeMethodWeight($weight);
            $shippingAddress->setCollectShippingRates(true)->collectShippingRates()->save();
        }

        $extensionAttributes = $quote->getExtensionAttributes();
        if (!empty($extensionAttributes)) {
            $shippingAssignments = $extensionAttributes->getShippingAssignments();
            if ($shippingAssignments) {
                foreach ($shippingAssignments as $assignment) {
                    $assignment->getShipping()->setMethod($shippingAddress->getShippingMethod());
                }
            }
        }

        $quote->collectTotals();
        $payment = $quote->getPayment();
        $payment->importData($data);

        $shippingAddress->save();
        $this->quoteRepository->save($quote);
    }

    /**
     * Calculate the total weight of all applicable items in the quote.
     */
    public function getQuoteItemsWeight(MagentoQuote $quote): float
    {
        $computedWeight = 0.0;

        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($quote->getAllItems() as $item) {
            // Skip virtual items — they don't ship and must not influence the weight that
            // Unifaun / Ingrid use to validate the shipment (otherwise mixed virtual+physical
            // carts can be rejected by the carrier with a generic warning at postcode entry).
            if ($item->getIsVirtual() || ($item->getProduct() && $item->getProduct()->getIsVirtual())) {
                continue;
            }
            if ($item->getRowWeight() > 0) {
                $computedWeight += (float) $item->getRowWeight();
            }
        }

        return $computedWeight;
    }

    /**
     * Get (or create) a Qliro link for the given quote.
     *
     * @throws AlreadyExistsException
     */
    public function getLinkFromQuote(MagentoQuote $quote): LinkInterface
    {
        $quoteId = $quote->getEntityId();

        try {
            $link = $this->linkRepository->getByQuoteId($quoteId);
            $this->logManager->debug('Link found for quote ' . $quoteId);
        } catch (NoSuchEntityException $exception) {
            $this->logManager->debug('No Link found for quote ' . $quoteId . ', creating new one');
            /** @var LinkInterface $link */
            $link = $this->linkFactory->create();
            $link->setRemoteIp($this->remoteAddress->getRemoteAddress());
            $link->setIsActive(true);
            $link->setQuoteId($quoteId);
            $this->logManager->debug('Link created, quote_id: ' . $quoteId);
        }

        $this->handleCountrySelect($link);

        if ($link->getQliroOrderId()) {
            $this->logManager->debug('Existing active Qliro link found; skipping legacy update flow');
        } else {
            if ($this->qliroConfig->useIncrementIdAsReference()) {
                // Settlement-friendly reference: use the Magento increment_id so PayPal /
                // Qliro settlements match Magento orders directly. Trade-off: the
                // increment_id is reserved at checkout init, so abandoned checkouts leave
                // gaps in the Magento order sequence.
                if (!$quote->getReservedOrderId()) {
                    $quote->reserveOrderId();
                    $this->quoteRepository->save($quote);
                }
                $orderReference = (string) $quote->getReservedOrderId();
                $this->logManager->debug('Qliro order reference (reserved increment_id): ' . $orderReference);
            } else {
                // Default: random hash reference (no order-sequence side effects).
                $orderReference = $this->linkService->generateOrderReference($quote);
                $this->logManager->debug('Qliro order reference (random hash): ' . $orderReference);
            }

            $this->logManager->setMerchantReference($orderReference);

            $payload = $this->createRequestBuilder->setQuote($quote)->create();
            $payload['MerchantReference'] = $orderReference;

            $this->logManager->debug('Sending request to create order ' . $orderReference);
            try {
                $orderId = $this->merchantApi->createOrder($payload);
            } catch (\Qliro\QliroOne\Model\Api\Client\Exception\OrderAlreadyExistsException $alreadyExists) {
                $orderReference = $orderReference . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                $payload['MerchantReference'] = $orderReference;
                $this->logManager->setMerchantReference($orderReference);
                $this->logManager->warning(
                    'Qliro reference collision recovered with suffixed reference: ' . $orderReference,
                    ['extra' => ['original_error' => $alreadyExists->getMessage()]]
                );
                $orderId = $this->merchantApi->createOrder($payload);
            }
            $this->logManager->debug('Order created ' . $orderId);

            $link->setQuoteSnapshot(null);
            $link->setIsActive(true);
            $link->setReference($orderReference);
            $link->setQliroOrderId($orderId);
            $this->logManager->debug('Saving Link: ' . $link->getReference());
            $this->linkRepository->save($link);
        }

        return $link;
    }

    /**
     * Update customer on the quote from QliroOne frontend callback data.
     *
     * @param array $customerData
     * @throws \Exception
     */
    public function updateCustomer(MagentoQuote $quote, array $customerData): void
    {
        $this->customerConverter->convert($customerData, $quote);
        $this->recalculateAndSaveQuote($quote);
        $this->updateQliroOrder($quote);
    }

    /**
     * Update shipping price in quote.
     * Returns true if the price was applied and quote was saved.
     *
     * @param float|null $price
     * @throws \Exception
     */
    public function updateShippingPrice(MagentoQuote $quote, ?float $price): bool
    {
        if ($this->isQuoteLocked($quote)) {
            $this->logManager->warning(
                'AJAX:UPDATE_SHIPPING_PRICE: denied — quote is locked (validate already ran). '
                . 'Keeping Magento shipping price as-is.',
                ['extra' => [
                    'quote_id'        => $quote->getId(),
                    'requested_price' => $price,
                ]]
            );
            return false;
        }

        if ($price === null) {
            $this->logManager->debug('AJAX:UPDATE_SHIPPING_PRICE: skip reason', [
                'extra' => ['message' => 'Price is empty'],
            ]);
            return false;
        }

        if ($quote->isVirtual()) {
            $this->logManager->debug('AJAX:UPDATE_SHIPPING_PRICE: skip reason', [
                'extra' => ['message' => 'Virtual quote cant be used to set shipping data'],
            ]);
            return false;
        }

        $container = new DataObject([
            'shipping_price' => $price,
            'can_save_quote' => false,
        ]);

        $this->eventManager->dispatch('qliroone_shipping_price_update_before', [
            'quote'     => $quote,
            'container' => $container,
        ]);

        $this->logManager->debug('Starting to update shipping price in Qliro quote ' . $quote->getId());
        $this->updateReceivedAmount($quote, $container);

        if ($container->getCanSaveQuote()) {
            $this->recalculateAndSaveQuote($quote);
            $this->logShippingDivergence($quote, (float) $price, 'updateShippingPrice');
            $this->logManager->debug('Finished updating shipping price in Qliro quote ' . $quote->getId());
            return true;
        }

        return false;
    }

    /**
     * Emit a structured warning when Magento's stored shipping amount diverges from
     * what Qliro reported. We don't raise an exception (would break checkout); the goal
     * is to make the next "5 kr off" or "method differs" mismatch immediately findable
     * in the logs, with quote_id, both totals, the method, and the source of the call.
     *
     * Tolerance of 0.005 keeps normal float rounding silent.
     */
    private function logShippingDivergence(MagentoQuote $quote, float $qliroPrice, string $source): void
    {
        if ($quote->isVirtual()) {
            return;
        }
        $shippingAddress = $quote->getShippingAddress();
        if (!$shippingAddress) {
            return;
        }

        $magentoIncl = (float) $shippingAddress->getShippingInclTax();
        $magentoExcl = (float) $shippingAddress->getShippingAmount();

        // Match either incl-tax or excl-tax against the Qliro value before warning.
        if (abs($magentoIncl - $qliroPrice) < 0.005 || abs($magentoExcl - $qliroPrice) < 0.005) {
            return;
        }

        $this->logManager->warning(
            'Shipping price mismatch between Magento and Qliro — investigate tax handling / carrier rate.',
            ['extra' => [
                'source'              => $source,
                'quote_id'            => $quote->getId(),
                'increment_id'        => $quote->getReservedOrderId(),
                'qliro_price'         => $qliroPrice,
                'magento_excl_tax'    => $magentoExcl,
                'magento_incl_tax'    => $magentoIncl,
                'shipping_method'     => $shippingAddress->getShippingMethod(),
                'shipping_description'=> $shippingAddress->getShippingDescription(),
            ]]
        );
    }

    /**
     * If freight amount comes from Qliro (Unifaun/Ingrid), store it on the link
     * so the Carrier can pick it up.
     */
    public function updateReceivedAmount(MagentoQuote $quote, DataObject $container): void
    {
        try {
            if ($this->qliroConfig->isUnifaunEnabled($quote->getStoreId())) {
                $link = $this->linkRepository->getByQuoteId($quote->getId());
                if ($link->getUnifaunShippingAmount() != $container->getData('shipping_price')) {
                    $link->setUnifaunShippingAmount($container->getData('shipping_price'));
                    $this->linkRepository->save($link);
                    $container->setData('can_save_quote', true);
                }
            }
            if ($this->qliroConfig->isIngridEnabled($quote->getStoreId())) {
                $link = $this->linkRepository->getByQuoteId($quote->getId());
                if ($link->getIngridShippingAmount() != $container->getData('shipping_price')) {
                    $link->setIngridShippingAmount($container->getData('shipping_price'));
                    $this->linkRepository->save($link);
                    $container->setData('can_save_quote', true);
                }
            }
        } catch (\Exception $exception) {
            // Non-fatal — log and continue
            $this->logManager->debug($exception);
        }
    }

    /**
     * Update QliroOne fee on quote.
     */
    public function updateFee(MagentoQuote $quote, float $fee): bool
    {
        try {
            $this->recalculateAndSaveQuote($quote);
        } catch (\Exception $exception) {
            try {
                $link = $this->getLinkFromQuote($quote);
            } catch (\Exception $e) {
                $link = null;
            }
            $this->logManager->critical($exception, [
                'extra' => ['qliro_order_id' => $link ? $link->getOrderId() : null],
            ]);
            return false;
        }

        return true;
    }

    /**
     * Push updated order data (items + shipping methods) to Qliro after quote changes.
     */
    public function updateQliroOrder(MagentoQuote $quote): void
    {
        try {
            $link = $this->linkRepository->getByQuoteId($quote->getId());
            $qliroOrderId = $link->getQliroOrderId();
            if (!$qliroOrderId) {
                return;
            }
            $payload = $this->updateRequestBuilder->setQuote($quote)->create();
            try {
                $this->merchantApi->updateOrder($qliroOrderId, $payload);
            } catch (\Qliro\QliroOne\Model\Api\Client\Exception\OrderExpiredException $expired) {
                // Qliro session/order has aged out. Deactivate the link so the next
                // checkout-page load (or quote read) recreates a fresh Qliro order with
                // a new unique merchant reference. We swallow here on purpose — pushing
                // updates is best-effort, and surfacing this as a critical to the user
                // would break the AJAX-triggered iframe refresh.
                $this->logManager->warning(
                    'Qliro ORDER_EXPIRED during updateOrder push — deactivating link to allow recreate on next load.',
                    ['extra' => [
                        'expired_qliro_order_id' => $qliroOrderId,
                        'quote_id'               => $quote->getId(),
                    ]]
                );
                // Clear only what's strictly required: qliroOrderId triggers the recreate
                // path on the next load; qliroOrderStatus is reset so applyQliroOrderStatus()
                // can't act on the expired order's stale status. setReference() and
                // setMessage() are typed `string` (not `?string`); 'reference' will be
                // overwritten by Quote::getLinkFromQuote() on the recreate.
                $link->setQliroOrderId(null);
                $link->setQliroOrderStatus('');
                $link->setMessage('Previous Qliro order expired during update — pending recreate on next checkout load.');
                $this->linkRepository->save($link);
                if ($this->qliroConfig->useIncrementIdAsReference()) {
                    $quote->setReservedOrderId(null);
                    $this->quoteRepository->save($quote);
                }
                return;
            }
            $this->logManager->debug('Pushed order update to Qliro', [
                'extra' => ['qliro_order_id' => $qliroOrderId, 'quote_id' => $quote->getId()],
            ]);
        } catch (\Exception $exception) {
            $this->logManager->critical($exception, [
                'extra' => ['quote_id' => $quote->getId()],
            ]);
        }
    }

    /**
     * If quote was not active when loaded, it may be missing Items — complete loading via LoadHandler.
     */
    private function completeQuoteLoading(MagentoQuote $quote): void
    {
        if ($quote->getIsActive()) {
            return;
        }

        $origActiveValue = $quote->getIsActive();
        $quote->setIsActive(true);
        $this->loadHandler->load($quote);
        $quote->setIsActive($origActiveValue);
    }

    /**
     * Handle country selector: if the customer changed country, reset the Qliro order ID
     * so a new one is created.
     */
    private function handleCountrySelect(LinkInterface $link): void
    {
        if (!$this->countrySelectManagement->isEnabled()) {
            return;
        }

        if ($this->countrySelectManagement->countryHasChanged()) {
            $link->setQliroOrderId(null);
            $this->linkRepository->save($link);
        }
    }

    /**
     * Whether the link for this quote is locked (validate already ran).
     *
     * No link → not locked. Lookup failure is logged and treated as not-locked (fail open)
     * so a flaky DB read can't permanently block shipping price updates.
     */
    private function isQuoteLocked(MagentoQuote $quote): bool
    {
        if (!$quote->getId()) {
            return false;
        }
        try {
            return $this->linkRepository->getByQuoteId((int) $quote->getId())->getIsLocked();
        } catch (NoSuchEntityException $e) {
            return false;
        } catch (\Exception $e) {
            $this->logManager->debug($e, ['extra' => ['quote_id' => $quote->getId()]]);
            return false;
        }
    }
}

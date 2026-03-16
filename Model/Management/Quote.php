<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote as MagentoQuote;
use Magento\Quote\Model\QuoteRepository\LoadHandler;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Carrier\Ingrid;
use Qliro\QliroOne\Model\Carrier\Unifaun;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Method\QliroOne;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter;
use Qliro\QliroOne\Helper\Data as Helper;
use Qliro\QliroOne\Service\General\LinkService;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Quote operations for QliroOne checkout.
 *
 * All methods accept the quote as an explicit parameter — no mutable
 * setQuote/getQuote state is maintained on this class.
 */
class Quote
{
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly LinkService $linkService,
        private readonly MerchantInterface $merchantApi,
        private readonly CreateRequestBuilder $createRequestBuilder,
        private readonly UpdateRequestBuilder $updateRequestBuilder,
        private readonly CustomerConverter $customerConverter,
        private readonly LinkInterfaceFactory $linkFactory,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly LogManager $logManager,
        private readonly Json $json,
        private readonly Fee $fee,
        private readonly Helper $helper,
        private readonly ManagerInterface $eventManager,
        private readonly LoadHandler $loadHandler,
        private readonly CountrySelect $countrySelectManagement
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
            if ($this->qliroConfig->isUnifaunEnabled($quote->getStoreId())) {
                $shippingAddress->setShippingMethod(Unifaun::QLIRO_UNIFAUN_SHIPPING_CODE);
            }
            if ($this->qliroConfig->isIngridEnabled($quote->getStoreId())) {
                $shippingAddress->setShippingMethod(Ingrid::QLIRO_INGRID_SHIPPING_CODE);
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
            if ($item->getRowWeight() > 0) {
                $computedWeight += (float) $item->getRowWeight();
            }
        }

        return $computedWeight;
    }

    /**
     * Get (or create) a Qliro link for the given quote.
     *
     * @throws \Magento\Framework\Exception\AlreadyExistsException
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
            $link->setRemoteIp($this->helper->getRemoteIp());
            $link->setIsActive(true);
            $link->setQuoteId($quoteId);
            $this->logManager->debug('Link created, quote_id: ' . $quoteId);
        }

        $this->handleCountrySelect($link);

        if ($link->getQliroOrderId()) {
            $this->logManager->debug('Existing active Qliro link found; skipping legacy update flow');
        } else {
            $this->logManager->debug('Generating new qliro order reference for quote ' . $quoteId);
            $orderReference = $this->linkService->generateOrderReference($quote);
            $this->logManager->debug('Qliro order reference created: ' . $orderReference);
            $this->logManager->setMerchantReference($orderReference);

            $this->logManager->debug('Creating request payload for Qliro order reference: ' . $orderReference);
            $payload = $this->createRequestBuilder->setQuote($quote)->create();
            $payload['MerchantReference'] = $orderReference;
            $this->logManager->debug('Request payload for Qliro order reference created: ' . $orderReference);

            $this->logManager->debug('Sending request to create order ' . $orderReference);
            $orderId = $this->merchantApi->createOrder($payload);
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
            $this->logManager->debug('Finished updating shipping price in Qliro quote ' . $quote->getId());
            return true;
        }

        return false;
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
    private function updateQliroOrder(MagentoQuote $quote): void
    {
        try {
            $link = $this->linkRepository->getByQuoteId($quote->getId());
            $qliroOrderId = $link->getQliroOrderId();
            if (!$qliroOrderId) {
                return;
            }
            $payload = $this->updateRequestBuilder->setQuote($quote)->create();
            $this->merchantApi->updateOrder($qliroOrderId, $payload);
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
     * If quote was not active when loaded it may be missing Items — complete loading via LoadHandler.
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
}

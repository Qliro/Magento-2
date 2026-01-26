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
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Method\QliroOne;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter;
use Qliro\QliroOne\Model\Management\CountrySelect;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote as ModelQuote;
use Magento\Quote\Model\QuoteRepository\LoadHandler;
use Qliro\QliroOne\Helper\Data as Helper;
use Qliro\QliroOne\Service\General\LinkService;

/**
 * QliroOne management class
 */
class Quote extends AbstractManagement
{
    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var LinkService
     */
    private $linkService;

    /**
     * @var \Qliro\QliroOne\Api\Client\MerchantInterface
     */
    private $merchantApi;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder
     */
    private $createRequestBuilder;

    /**
     * @var \Qliro\QliroOne\Api\Data\LinkInterfaceFactory
     */
    private $linkFactory;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var \Qliro\QliroOne\Model\ContainerMapper
     */
    private $containerMapper;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder
     */
    private $updateRequestBuilder;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $json;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter
     */
    private $customerConverter;

    /**
     * @var \Qliro\QliroOne\Model\Fee
     */
    private $fee;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private $helper;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var LoadHandler
     */
    private LoadHandler $loadHandler;

    private CountrySelect $countrySelectManagement;

    /**
     * Inject dependencies
     * @param Config $qliroConfig
     * @param LinkService $merchantReferenceGenerator
     * @param MerchantInterface $merchantApi
     * @param CreateRequestBuilder $createRequestBuilder
     * @param UpdateRequestBuilder $updateRequestBuilder
     * @param CustomerConverter $customerConverter
     * @param LinkInterfaceFactory $linkFactory
     * @param LinkRepositoryInterface $linkRepository
     * @param CartRepositoryInterface $quoteRepository
     * @param ContainerMapper $containerMapper
     * @param LogManager $logManager
     * @param Json $json
     * @param Fee $fee
     * @param Helper $helper
     * @param ManagerInterface $eventManager,
     * @param LoadHandler $loadHandler
     * @param CountrySelect $countrySelectManagement
     */
    public function __construct(
        Config $qliroConfig,
        LinkService $merchantReferenceGenerator,
        MerchantInterface $merchantApi,
        CreateRequestBuilder $createRequestBuilder,
        UpdateRequestBuilder $updateRequestBuilder,
        CustomerConverter $customerConverter,
        LinkInterfaceFactory $linkFactory,
        LinkRepositoryInterface $linkRepository,
        CartRepositoryInterface $quoteRepository,
        ContainerMapper $containerMapper,
        LogManager $logManager,
        Json $json,
        Fee $fee,
        Helper $helper,
        ManagerInterface $eventManager,
        LoadHandler $loadHandler,
        CountrySelect $countrySelectManagement
    ) {
        $this->qliroConfig = $qliroConfig;
        $this->linkService = $merchantReferenceGenerator;
        $this->merchantApi = $merchantApi;
        $this->createRequestBuilder = $createRequestBuilder;
        $this->linkFactory = $linkFactory;
        $this->linkRepository = $linkRepository;
        $this->quoteRepository = $quoteRepository;
        $this->containerMapper = $containerMapper;
        $this->logManager = $logManager;
        $this->updateRequestBuilder = $updateRequestBuilder;
        $this->json = $json;
        $this->customerConverter = $customerConverter;
        $this->fee = $fee;
        $this->helper = $helper;
        $this->eventManager = $eventManager;
        $this->loadHandler = $loadHandler;
        $this->countrySelectManagement = $countrySelectManagement;
    }

    /**
     * Recalculate the quote, its totals, it's addresses and shipping rates, then saving quote
     *
     * @throws \Exception
     */
    public function recalculateAndSaveQuote()
    {
        $data['method'] = QliroOne::PAYMENT_METHOD_CHECKOUT_CODE;

        $quote = $this->getQuote();
        $customer = $quote->getCustomer();
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress = $quote->getBillingAddress();

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
                $shippingAddress->setShippingMethod(
                    \Qliro\QliroOne\Model\Carrier\Unifaun::QLIRO_UNIFAUN_SHIPPING_CODE
                );
            }
            if ($this->qliroConfig->isIngridEnabled($quote->getStoreId())) {
                $shippingAddress->setShippingMethod(
                    \Qliro\QliroOne\Model\Carrier\Ingrid::QLIRO_INGRID_SHIPPING_CODE
                );
            }
            if(!$shippingAddress->hasData('item_qty')) {
                $shippingAddress->setData('item_qty', $quote->getItemsQty());//fix magento bug for shipping per item
            }

            $weight = $this->getQuoteItemsWeight();
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
     * Calculates the total weight of all applicable items in the quote.
     *
     * This method iterates through the quote's items to compute the combined
     * weight of items that contribute to the shipping weight.
     *
     * @return float The total calculated weight of the quote items.
     */
    public function getQuoteItemsWeight(): float
    {
        $quote = $this->getQuote();

        $computedWeight = 0.0;

        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($quote->getAllItems() as $item) {
            if ($item->getRowWeight() > 0) {
                $computedWeight = $computedWeight + floatval($item->getRowWeight());
            }
        }

        return $computedWeight;
    }

    /**
     * Get a link for the current quote
     *
     * @return \Qliro\QliroOne\Api\Data\LinkInterface
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function getLinkFromQuote()
    {
        $quote = $this->getQuote();
        $quoteId = $quote->getEntityId();

        try {
            $link = $this->linkRepository->getByQuoteId($quoteId);
            $this->logManager->debug('Link found for quote ' . $quoteId);
        } catch (NoSuchEntityException $exception) {
            $this->logManager->debug('No Link found for quote ' . $quoteId . ', creating new one');
            /** @var \Qliro\QliroOne\Api\Data\LinkInterface $link */
            $link = $this->linkFactory->create();
            $link->setRemoteIp($this->helper->getRemoteIp());
            $link->setIsActive(true);
            $link->setQuoteId($quoteId);
            $this->logManager->debug('Link created, quote_id: ' . $quoteId);
        }

        $this->handleCountrySelect($link);

        if ($link->getQliroOrderId()) {
            $this->logManager->debug('Starting to update qliro order from quote ' . $quoteId);
            $this->update($link->getQliroOrderId());
            $this->logManager->debug('Updated qliro order from quote ' . $quoteId);
        } else {
            $this->logManager->debug('Generating new qliro order reference' . $quoteId);
            $orderReference = $this->linkService->generateOrderReference($quote);
            $this->logManager->debug('Qliro order reference created: ' . $orderReference);
            $this->logManager->setMerchantReference($orderReference);

            $this->logManager->debug('Creating request for Qliro order reference: ' . $orderReference);
            $request = $this->createRequestBuilder->setQuote($quote)->create();
            $request->setMerchantReference($orderReference);
            $this->logManager->debug('Request for Qliro order reference created: ' . $orderReference);

            try {
                $this->logManager->debug('Sending request to create order ' . $orderReference);
                $orderId = $this->merchantApi->createOrder($request);
                $this->logManager->debug('Order created ' . $orderId);
            } catch (\Exception $exception) {
                $this->logManager->debug('Order creation failed: ' . $exception->getMessage());
                $orderId = null;
            }

            $hash = $this->generateUpdateHash($quote);
            $link->setQuoteSnapshot($hash);

            $link->setIsActive(true);
            $link->setReference($orderReference);
            $link->setQliroOrderId($orderId);
            $this->logManager->debug('Saving Link: ' . $link->getReference());
            $this->linkRepository->save($link);
        }

        return $link;
    }

    /**
     * Update qliro order with information in quote
     *
     * @param int|null $orderId
     * @param bool $force
     */
    public function update($orderId, $force = false)
    {
        $this->logManager->setMark('UPDATE ORDER');

        try {
            $link = $this->linkRepository->getByQliroOrderId($orderId);
            $this->logManager->setMerchantReference($link->getReference());

            $isQliroOrderStatusEmpty = empty($link->getQliroOrderStatus());
            $isQliroOrderStatusInProcess = $link->getQliroOrderStatus() == CheckoutStatusInterface::STATUS_IN_PROCESS;

            if ($isQliroOrderStatusEmpty || $isQliroOrderStatusInProcess) {
                $this->logManager->debug('update qliro order');     // @todo: remove
                $quoteId = $link->getQuoteId();

                try {
                    /** @var \Magento\Quote\Model\Quote $quote */
                    $quote = $this->quoteRepository->get($quoteId);
                    $this->completeQuoteLoading($quote);

                    $hash = $this->generateUpdateHash($quote);

                    $this->logManager->debug(
                        sprintf(
                            'order hash is %s',
                            $link->getQuoteSnapshot() === $hash ? 'same' : 'different'
                        )
                    );     // @todo: remove

                    if ($force || $this->canUpdateOrder($hash, $link)) {
                        $request = $this->updateRequestBuilder->setQuote($quote)->create();
                        $this->merchantApi->updateOrder($orderId, $request);
                        $link->setQuoteSnapshot($hash);
                        $this->linkRepository->save($link);
                        $this->logManager->debug(sprintf('updated order %s', $orderId));     // @todo: remove
                    }
                } catch (\Exception $exception) {
                    if ($link && $link->getId()) {
                        $link->setIsActive(false);
                        $link->setMessage($exception->getMessage());
                        $this->linkRepository->save($link);
                    }
                    $this->logManager->critical(
                        $exception,
                        [
                            'extra' => [
                                'qliro_order_id' => $orderId,
                                'quote_id' => $quoteId,
                                'link_id' => $link->getId()
                            ],
                        ]
                    );
                }
            } else {
                $this->logManager->debug('Order update is skipped due to not suitable order status. Current order status: ' . $link->getQliroOrderStatus());
            }
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $orderId,
                    ],
                ]
            );
        } finally {
            $this->logManager->setMark(null);
        }
    }

    /**
     * Check if QliroOne order can be updated
     *
     * @param string $hash
     * @param \Qliro\QliroOne\Api\Data\LinkInterface $link
     * @return bool
     */
    private function canUpdateOrder($hash, LinkInterface $link)
    {
        return empty($this->getQuote()->getShippingAddress()->getShippingMethod()) || $link->getQuoteSnapshot() !== $hash;
    }

    /**
     * Generate a hash for quote content comparison
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return string
     */
    private function generateUpdateHash($quote)
    {
        $request = $this->updateRequestBuilder->setQuote($quote)->create();
        $data = $this->containerMapper->toArray($request);
        sort($data);

        try {
            $serializedData = $this->json->serialize($data);
        } catch (\InvalidArgumentException $exception) {
            $serializedData = null;
        }

        $hash = $serializedData ? md5($serializedData) : null;

        $this->logManager->debug(
            sprintf('generateUpdateHash: %s', $hash),
            ['extra' => var_export($data, true)]
        );     // @todo: remove

        return $hash;
    }

    /**
     * Update customer with data from QliroOne frontend callback
     *
     * @param array $customerData
     * @throws \Exception
     */
    public function updateCustomer($customerData)
    {
        /** @var \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface $qliroCustomer */
        $qliroCustomer = $this->containerMapper->fromArray($customerData, QliroOrderCustomerInterface::class);

        $this->customerConverter->convert($qliroCustomer, $this->getQuote());
        $this->recalculateAndSaveQuote();
    }

    /**
     * Update shipping price in quote
     * Return true in case shipping price was set, or false if the quote is virtual or update didn't happen
     *
     * @param float|null $price
     * @return bool
     * @throws \Exception
     */
    public function updateShippingPrice($price)
    {
        if (is_null($price)) {
            $this->logManager->debug(
                'AJAX:UPDATE_SHIPPING_PRICE: skip reason',
                [
                    'extra' => [
                        'message' => 'Price is empty',
                    ],
                ]
            );

            return false;
        }

        $quote = $this->getQuote();

        if ($quote->isVirtual()) {
            $this->logManager->debug(
                'AJAX:UPDATE_SHIPPING_PRICE: skip reason',
                [
                    'extra' => [
                        'message' => 'Virtual quote cant be used to set shipping data',
                    ],
                ]
            );

            return false;
        }

        // @codingStandardsIgnoreStart
        // phpcs:disable
        $container = new DataObject(
            [
                'shipping_price' => $price,
                'can_save_quote' => false,
            ]
        );
        // @codingStandardsIgnoreEnd
        // phpcs:enable

        $this->eventManager->dispatch(
            'qliroone_shipping_price_update_before',
            [
                'quote' => $quote,
                'container' => $container,
            ]
        );
        $this->logManager->debug('Starting to update shipping price in Qliro quote ' . $quote->getId());
        $this->updateReceivedAmount($container);

        if ($container->getCanSaveQuote()) {
            $this->recalculateAndSaveQuote();
            $this->logManager->debug('Finished to update shipping price in Qliro quote ' . $quote->getId());

            return true;
        }

        return false;
    }

    /**
     * If freight amount comes from Qliro, it's Unifaun and that amount has to be stored for Carrier to pick up
     *
     * @param $container
     */
    public function updateReceivedAmount($container)
    {
        try {
            $quote = $this->getQuote();
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
        }
    }

    /**
     * Update selected shipping method in quote
     * Return true in case shipping method was set, or false if the quote is virtual or method was not changed
     *
     * @param float $fee
     * @return bool
     * @throws \Exception
     */
    public function updateFee($fee)
    {
        try {
            //$this->fee->setQlirooneFeeInclTax($this->getQuote(), $fee);
            $this->recalculateAndSaveQuote();
        } catch (\Exception $exception) {
            $link = $this->getLinkFromQuote();
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $link->getOrderId(),
                    ],
                ]
            );

            return false;
        }

        return true;
    }

    /**
     * If quote was not active when loaded, it will be missing some necessary data such as Items.
     * In this case, we complete the loading here using the load handler.
     *
     * @param ModelQuote $quote
     * @return void
     */
    private function completeQuoteLoading(ModelQuote $quote): void
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
     * Handles country selector logic
     * If country selector is enabled, and customer has changed country,
     * we reset the Qliro order id so a new will be created
     *
     * @param LinkInterface $link
     * @return void
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

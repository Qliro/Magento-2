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
use Magento\Framework\Serialize\SerializerInterface as JsonSerializer;
use Magento\Quote\Api\CartRepositoryInterface as CartRepository;
use Magento\Quote\Model\Quote as QuoteModel;
use Magento\Quote\Model\QuoteRepository\LoadHandler;
use Qliro\QliroOne\Api\Client\MerchantInterface as MerchantClient;
use Qliro\QliroOne\Api\Data\LinkInterface as LinkData;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory as LinkFactory;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface as QliroOrderCustomer;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface as CheckoutStatus;
use Qliro\QliroOne\Api\LinkRepositoryInterface as LinkRepository;
use Qliro\QliroOne\Model\Carrier\Ingrid;
use Qliro\QliroOne\Model\Carrier\Unifaun;
use Qliro\QliroOne\Model\Config as ConfigModel;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Fee as FeeModel;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Method\QliroOne as QliroOnePaymentModel;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter;
use Qliro\QliroOne\Helper\Data as Helper;
use Qliro\QliroOne\Service\General\LinkService;

/**
 * Class Quote
 */
class Quote extends AbstractManagement
{
    /**
     * Class constructor
     *
     * @param EventManager                    $eventManager
     * @param JsonSerializer                  $jsonSerializer
     * @param CartRepository                  $quoteRepository
     * @param LoadHandler                     $loadHandler
     * @param MerchantClient                  $merchantClient
     * @param LinkFactory                     $linkFactory
     * @param LinkRepository                  $linkRepository
     * @param ConfigModel                     $configModel
     * @param ContainerMapper                 $containerMapper
     * @param FeeModel                        $feeModel
     * @param LogManager                      $logManager
     * @param CreateRequestBuilder            $createRequestBuilder
     * @param UpdateRequestBuilder            $updateRequestBuilder
     * @param CustomerConverter               $customerConverter
     * @param Helper                          $helper
     * @param LinkService                     $linkService
     * @param CountrySelect                   $countrySelectManagement
     */
    public function __construct(
        private readonly EventManager         $eventManager,
        private readonly JsonSerializer       $jsonSerializer,
        private readonly CartRepository       $quoteRepository,
        private readonly LoadHandler          $loadHandler,
        private readonly MerchantClient       $merchantClient,
        private readonly LinkFactory          $linkFactory,
        private readonly LinkRepository       $linkRepository,
        private readonly ConfigModel          $configModel,
        private readonly ContainerMapper      $containerMapper,
        private FeeModel                      $feeModel,
        private readonly LogManager           $logManager,
        private readonly CreateRequestBuilder $createRequestBuilder,
        private readonly UpdateRequestBuilder $updateRequestBuilder,
        private readonly CustomerConverter    $customerConverter,
        private readonly Helper               $helper,
        private readonly LinkService          $linkService,
        private readonly CountrySelect        $countrySelectManagement
    ) {
    }

    /**
     * Recalculate the quote, its totals, it's addresses and shipping rates, then saving quote
     *
     * @throws \Exception
     */
    public function recalculateAndSaveQuote(): void
    {
        $data['method'] = QliroOnePaymentModel::PAYMENT_METHOD_CHECKOUT_CODE;

        $quote = $this->getQuote();
        $this->logManager->debug('Recalculating quote: ' . $quote->getId());
        $customer = $quote->getCustomer();
        $shippingAddress = $quote->getShippingAddress();
        $this->logManager->debug('Shipping method from quote: ' . $shippingAddress->getShippingMethod());
        $billingAddress = $quote->getBillingAddress();

        $quote->isVirtual() ?
            $billingAddress->setPaymentMethod($data['method']) : $shippingAddress->setPaymentMethod($data['method']);

        $billingAddress->save();

        if (!$quote->isVirtual()) {
            $shippingAddress->save();
        }

        $quote->assignCustomerWithAddressChange($customer, $billingAddress, $shippingAddress);
        $quote->setTotalsCollectedFlag(false);

        if (!$quote->isVirtual() && $shippingAddress->getShippingMethod() !== '') {
            if ($this->configModel->isUnifaunEnabled($quote->getStoreId())) {
                $shippingAddress->setShippingMethod(Unifaun::QLIRO_UNIFAUN_SHIPPING_CODE);
            }
            if ($this->configModel->isIngridEnabled($quote->getStoreId())) {
                $shippingAddress->setShippingMethod(Ingrid::QLIRO_INGRID_SHIPPING_CODE);
            }
            if (!$shippingAddress->hasData('item_qty')) {
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
     * @return LinkData
     * @throws AlreadyExistsException
     */
    public function getLinkFromQuote(): LinkData
    {
        $quote = $this->getQuote();
        $quoteId = $quote->getEntityId();

        try {
            $link = $this->linkRepository->getByQuoteId($quoteId);
        } catch (NoSuchEntityException $exception) {
            $link = $this->linkFactory->create();
            $link->setRemoteIp($this->helper->getRemoteIp());
            $link->setIsActive(true);
            $link->setQuoteId($quoteId);
        }

        $this->handleCountrySelect($link);

        if ($link->getQliroOrderId()) {
            $this->update((int)$link->getQliroOrderId());
        } else {
            $this->logManager->debug('create new qliro order'); // @todo: remove
            $orderReference = $this->linkService->generateOrderReference($quote);
            $this->logManager->setMerchantReference($orderReference);

            $request = $this->createRequestBuilder->setQuote($quote)->create();
            $request->setMerchantReference($orderReference);

            try {
                $orderId = $this->merchantClient->createOrder($request);
            } catch (\Exception $exception) {
                $orderId = null;
            }

            $hash = $this->generateUpdateHash($quote);
            $link->setQuoteSnapshot($hash);

            $link->setIsActive(true);
            $link->setReference($orderReference);
            $link->setQliroOrderId($orderId);
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
    public function update(?int $orderId, bool $force = false): void
    {
        $this->logManager->setMark('UPDATE ORDER');

        try {
            $link = $this->linkRepository->getByQliroOrderId($orderId);
            $this->logManager->setMerchantReference($link->getReference());

            $isQliroOrderStatusEmpty = empty($link->getQliroOrderStatus());
            $isQliroOrderStatusInProcess = $link->getQliroOrderStatus() == CheckoutStatus::STATUS_IN_PROCESS;

            if ($isQliroOrderStatusEmpty || $isQliroOrderStatusInProcess) {
                $this->logManager->debug('update qliro order');     // @todo: remove
                $quoteId = $link->getQuoteId();

                try {
                    /** @var QuoteModel $quote */
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
                        $this->merchantClient->updateOrder($orderId, $request);
                        $link->setQuoteSnapshot($hash);
                        $this->linkRepository->save($link);
                        $this->logManager->debug(sprintf('updated order %s', $orderId));     // @todo: remove
                    }
                } catch (\Exception $exception) {
                    if ($link->getId()) {
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
     * @param LinkData $link
     * @return bool
     */
    private function canUpdateOrder(string $hash, LinkData $link): bool
    {
        return empty($this->getQuote()->getShippingAddress()->getShippingMethod()) || $link->getQuoteSnapshot() !== $hash;
    }

    /**
     * Generate a hash for quote content comparison
     *
     * @param QuoteModel $quote
     * @return string
     */
    private function generateUpdateHash(QuoteModel $quote): ?string
    {
        $request = $this->updateRequestBuilder->setQuote($quote)->create();
        $data = $this->containerMapper->toArray($request);
        sort($data);

        try {
            $serializedData = $this->jsonSerializer->serialize($data);
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
    public function updateCustomer(array $customerData): void
    {
        /** @var QliroOrderCustomer $qliroCustomer */
        $qliroCustomer = $this->containerMapper->fromArray($customerData, QliroOrderCustomer::class);

        if (empty($qliroCustomer->getAddress()->getCountryCode())) {
            $qliroCustomer->getAddress()->setCountryCode($customerData['address']['country_id']);
        }

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
    public function updateShippingPrice(?float $price): bool
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
        $this->updateReceivedAmount($container);

        if ($container->getCanSaveQuote()) {
            $this->recalculateAndSaveQuote();

            return true;
        }

        return false;
    }

    /**
     * If freight amount comes from Qliro, it's Unifaun and that amount has to be stored for Carrier to pick up
     *
     * @param $container
     */
    public function updateReceivedAmount($container): void
    {
        try {
            $quote = $this->getQuote();
            if ($this->configModel->isUnifaunEnabled($quote->getStoreId())) {
                $link = $this->linkRepository->getByQuoteId($quote->getId());
                if ($link->getUnifaunShippingAmount() != $container->getData('shipping_price')) {
                    $link->setUnifaunShippingAmount($container->getData('shipping_price'));
                    $this->linkRepository->save($link);
                    $container->setData('can_save_quote', true);
                }
            }
            if ($this->configModel->isIngridEnabled($quote->getStoreId())) {
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
    public function updateFee(float $fee): bool
    {
        try {
            //$this->feeModel->setQlirooneFeeInclTax($this->getQuote(), $fee);
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
     * @param QuoteModel $quote
     * @return void
     */
    private function completeQuoteLoading(QuoteModel $quote): void
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
     * @param LinkData $link
     * @return void
     * @throws AlreadyExistsException
     */
    private function handleCountrySelect(LinkData $link): void
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

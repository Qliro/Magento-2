<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromShippingMethodsConverter;

/**
 * QliroOne management class
 */
class ShippingMethod extends AbstractManagement
{
    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder
     */
    private $shippingMethodsBuilder;

    /**
     * @var \Qliro\QliroOne\Model\ContainerMapper
     */
    private $containerMapper;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromShippingMethodsConverter
     */
    private $quoteFromShippingMethodsConverter;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var ScopeConfig
     */
    private ScopeConfig $scopeConfig;

    /**
     * @var Quote
     */
    private $quoteManagement;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Store\Model\App\Emulation
     */
    private $storeEmulation;

    /**
     * Inject dependencies
     *
     * @param ShippingMethodsBuilder $shippingMethodsBuilder
     * @param QuoteFromShippingMethodsConverter $quoteFromShippingConverter
     * @param LinkRepositoryInterface $linkRepository
     * @param CartRepositoryInterface $quoteRepository
     * @param ContainerMapper $containerMapper
     * @param LogManager $logManager
     * @param ManagerInterface $eventManager
     * @param ScopeConfig $scopeConfig
     * @param Quote $quoteManagement
     * @param StoreManagerInterface|null $storeManager
     * @param Emulation|null $storeEmulation
     */
    public function __construct(
        ShippingMethodsBuilder $shippingMethodsBuilder,
        QuoteFromShippingMethodsConverter $quoteFromShippingConverter,
        LinkRepositoryInterface $linkRepository,
        CartRepositoryInterface $quoteRepository,
        ContainerMapper $containerMapper,
        LogManager $logManager,
        ManagerInterface $eventManager,
        ScopeConfig $scopeConfig,
        Quote $quoteManagement,
        ?StoreManagerInterface $storeManager = null,
        ?Emulation $storeEmulation = null
    ) {
        $this->linkRepository = $linkRepository;
        $this->quoteRepository = $quoteRepository;
        $this->shippingMethodsBuilder = $shippingMethodsBuilder;
        $this->containerMapper = $containerMapper;
        $this->logManager = $logManager;
        $this->quoteFromShippingMethodsConverter = $quoteFromShippingConverter;
        $this->eventManager = $eventManager;
        $this->scopeConfig = $scopeConfig;
        $this->quoteManagement = $quoteManagement;
        // Optional so a subclass calling parent::__construct() with the old signature keeps
        // working. Magento passes null for optional arguments instead of resolving them, so
        // the instances are fetched here rather than left to DI.
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
        $this->storeEmulation = $storeEmulation ?: ObjectManager::getInstance()->get(Emulation::class);
    }

    /**
     * Update quote with received data in the container and return a list of available shipping methods
     *
     * @param \Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface $updateContainer
     * @return \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface
     */
    public function get(UpdateShippingMethodsNotificationInterface $updateContainer)
    {
        /** @var \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface $declineContainer */
        $declineContainer = $this->containerMapper->fromArray(
            ['DeclineReason' => UpdateShippingMethodsResponseInterface::REASON_POSTAL_CODE],
            UpdateShippingMethodsResponseInterface::class
        );

        try {
            $link = $this->linkRepository->getByQliroOrderId($updateContainer->getOrderId());
            $this->logManager->setMerchantReference($link->getReference());

            try {
                $this->setQuote($this->quoteRepository->get($link->getQuoteId()));

                return $this->buildInQuoteStore($updateContainer);
            } catch (\Exception $exception) {
                $this->logManager->critical(
                    $exception,
                    [
                        'extra' => [
                            'qliro_order_id' => $updateContainer->getOrderId(),
                            'quote_id' => $link->getQuoteId(),
                        ],
                    ]
                );

                return $declineContainer;
            }
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $updateContainer->getOrderId(),
                    ],
                ]
            );

            return $declineContainer;
        }
    }

    /**
     * Rate the quote in the store view it belongs to and build the response
     *
     * Qliro calls this back server to server, so the request carries no session, and the callback
     * URL carries no store code unless the merchant put one in every store's base URL. It resolves
     * to the default store view, and a quote from any other one would then be priced in another
     * store's currency and described in another store's language, with any carrier that reads the
     * current store rather than the rate request rating for another store as well.
     *
     * @param UpdateShippingMethodsNotificationInterface $updateContainer
     * @return \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface
     */
    private function buildInQuoteStore(UpdateShippingMethodsNotificationInterface $updateContainer)
    {
        $quoteStoreId = (int)$this->getQuote()->getStoreId();
        $isEmulated = false;

        // Store 0 is the admin store, which a quote never belongs to, so a quote that states it
        // states nothing. Emulating it would rate the order against the admin scope.
        if ($quoteStoreId > 0 && $quoteStoreId !== (int)$this->storeManager->getStore()->getId()) {
            $this->storeEmulation->startEnvironmentEmulation($quoteStoreId, Area::AREA_FRONTEND, true);
            // Magento allows a single level of emulation and refuses a nested one silently, so
            // the store the start actually produced is what decides whether this method owns a
            // stop. Stopping a refused one would end the emulation its caller is still inside.
            $isEmulated = $quoteStoreId === (int)$this->storeManager->getStore()->getId();
        }

        try {
            $this->quoteFromShippingMethodsConverter->convert($updateContainer, $this->getQuote());
            $this->quoteManagement->setQuote($this->getQuote())->recalculateAndSaveQuote();

            return $this->shippingMethodsBuilder->setQuote($this->getQuote())->create();
        } finally {
            if ($isEmulated) {
                $this->storeEmulation->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * Update selected shipping method in quote
     * Return true in case shipping method was set, or false if the quote is virtual or method was not changed
     *
     * @param string $code
     * @param string|null $secondaryOption
     * @param float|null $price
     * @return bool
     * @throws \Exception
     */
    public function update($code, $secondaryOption = null, $price = null)
    {
        $this->logManager->debug('Starting to update shipping method for quote: ' . $this->getQuote()->getId());
        $quote = $this->getQuote();

        if ($code && !$quote->isVirtual()) {
            $this->logManager->debug('Code for quote is: ' . $code);
            $shippingAddress = $quote->getShippingAddress();

            if (!$shippingAddress->getPostcode()) {
                $billingAddress = $quote->getBillingAddress();
                $shippingAddress->addData(
                    [
                        'email' => $billingAddress->getEmail(),
                        'firstname' => $billingAddress->getFirstname(),
                        'lastname' => $billingAddress->getLastname(),
                        'company' => $billingAddress->getCompany(),
                        'street' => $billingAddress->getStreetFull(),
                        'city' => $billingAddress->getCity(),
                        'region' => $billingAddress->getRegion(),
                        'region_id' => $billingAddress->getRegionId(),
                        'postcode' => $billingAddress->getPostcode(),
                        'country_id' => $billingAddress->getCountryId(),
                        'telephone' => $billingAddress->getTelephone(),
                        'same_as_billing' => true,
                    ]
                );
            }

            // @codingStandardsIgnoreStart
            // phpcs:disable
            $container = new DataObject(
                [
                    'shipping_method' => $code,
                    'secondary_option' => $secondaryOption,
                    'shipping_price' => $price,
                    'can_save_quote' => $shippingAddress->getShippingMethod() !== $code,
                ]
            );
            // @codingStandardsIgnoreEnd
            // phpcs:enable

            $this->eventManager->dispatch(
                'qliroone_shipping_method_update_before',
                [
                    'quote' => $quote,
                    'container' => $container,
                ]
            );
            $this->quoteManagement->setQuote($this->getQuote())->updateReceivedAmount($container);

            if (!$container->getCanSaveQuote()) {
                $this->logManager->debug(
                    'AJAX:UPDATE_SHIPPING_METHOD: skip reason',
                    [
                        'extra' => [
                            'message' => 'Shipping method is already set',
                            'quote_method' => $shippingAddress->getShippingMethod(),
                            'qliro_method' => $code,
                        ],
                    ]
                );
                return false;
            }

            $shippingAddress->setShippingMethod($container->getShippingMethod());

            // Resolved for the quote's store rather than the current one, and ScopeConfig
            // already falls back to website and default on its own
            $defaultCountry = $this->scopeConfig->getValue(
                'general/country/default',
                ScopeInterface::SCOPE_STORE,
                $quote->getStoreId()
            );

            if (!$shippingAddress->getCountryId()) {
                $shippingAddress->setCountryId($defaultCountry);
            }

            if (!$quote->getBillingAddress()->getCountryId()) {
                $quote->getBillingAddress()->setCountryId($defaultCountry);
            }

            $this->quoteManagement->recalculateAndSaveQuote();

            // For some reason shipping code that was previously set, is not applied
            if ($shippingAddress->getShippingMethod() !== $container->getShippingMethod()) {
                $this->logManager->debug('Shipping method from quote: ' . $shippingAddress->getShippingMethod() .
                ' not equal to shipping method from container: ' . $container->getShippingMethod()
                );
                $this->logManager->debug(
                    'AJAX:UPDATE_SHIPPING_METHOD: skip reason',
                    [
                        'extra' => [
                            'message' => 'Unable to change shipping method. Check magento and server logs',
                            'quote_method' => $shippingAddress->getShippingMethod(),
                            'qliro_method' => $code,
                        ],
                    ]
                );
                return false;
            }

            return true;
        }

        return false;
    }
}

<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory;
use Qliro\QliroOne\Model\Carrier\Ingrid;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Shipping Methods Builder class
 */
class ShippingMethodsBuilder
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * @var \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory
     */
    private $shippingMethodsResponseFactory;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder
     */
    private $shippingMethodBuilder;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory $shippingMethodsResponseFactory
     * @param \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder $shippingMethodBuilder
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param StoreManagerInterface $storeManager
     * @param Config $qliroConfig
     * @param LogManager|null $logManager
     */
    public function __construct(
        UpdateShippingMethodsResponseInterfaceFactory $shippingMethodsResponseFactory,
        ShippingMethodBuilder $shippingMethodBuilder,
        ManagerInterface $eventManager,
        StoreManagerInterface $storeManager,
        Config $qliroConfig,
        ?LogManager $logManager = null,
    ) {
        $this->shippingMethodsResponseFactory = $shippingMethodsResponseFactory;
        $this->shippingMethodBuilder = $shippingMethodBuilder;
        $this->eventManager = $eventManager;
        $this->storeManager = $storeManager;
        $this->qliroConfig = $qliroConfig;
        // Optional so a subclass calling parent::__construct() with the old signature keeps
        // working. Magento passes null for optional arguments instead of resolving them, so
        // the instance is fetched here rather than left to DI.
        $this->logManager = $logManager ?: ObjectManager::getInstance()->get(LogManager::class);
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return $this
     */
    public function setQuote(Quote $quote)
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * @return \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface
     */
    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        /** @var \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface $container */
        $container = $this->shippingMethodsResponseFactory->create();

        if ($this->qliroConfig->isUnifaunEnabled($this->quote->getStoreId())) {
            return $container;
        }

        $this->quote->setTotalsCollectedFlag(false);
        $this->quote->collectTotals();
        $this->quote->getShippingAddress()
            ->setCollectShippingRates(true)
            ->collectShippingRates();

        $collectedShippingMethods = [];

        if ($this->quote->getIsVirtual()) {
            $container->setAvailableShippingMethods($collectedShippingMethods);
        } else {
            $collectedShippingMethods = $this->collectShippingMethods();
            if (empty($collectedShippingMethods)) {
                $this->logDecline();
                $container->setDeclineReason(UpdateShippingMethodsResponseInterface::REASON_POSTAL_CODE);
            } else {
                $container->setAvailableShippingMethods($collectedShippingMethods);
            }
        }

        $this->eventManager->dispatch(
            'qliroone_shipping_methods_response_build_after',
            [
                'quote' => $this->quote,
                'container' => $container,
            ]
        );

        $this->quote = null;

        return $container;
    }

    /**
     * Log why the quote produced no shipping method, so a decline is diagnosable
     *
     * @return void
     */
    private function logDecline(): void
    {
        $shippingAddress = $this->quote->getShippingAddress();
        $message = 'No shipping method available for the quote, declining with ' .
            UpdateShippingMethodsResponseInterface::REASON_POSTAL_CODE;
        $context = [
            'extra' => [
                'quote_id' => $this->quote->getId(),
                'postcode' => $shippingAddress->getPostcode(),
                'country_id' => $shippingAddress->getCountryId(),
                'collected_rates' => count($shippingAddress->getAllShippingRates()),
            ],
        ];

        // An address that cannot be rated yet is the normal state when the order is created,
        // before the customer has identified. Only a rateable address that yields nothing
        // points at a real problem.
        if (!$shippingAddress->getPostcode() || !$shippingAddress->getCountryId()) {
            $this->logManager->debug($message, $context);

            return;
        }

        $this->logManager->notice($message, $context);
    }

    /**
     * Collects and processes available shipping methods for the current quote.
     *
     * Gathers the shipping rates grouped by method and converts them into a structured format
     * while filtering out invalid or error-related shipping methods. Adjusts prices based on
     * the current store's currency and builds the corresponding shipping method containers.
     *
     * @return array Returns an array of processed shipping method objects that include
     *               valid merchant references and adjusted pricing details.
     */
     protected function collectShippingMethods(): array
     {
         $shippingMethods = [];
         $rateGroups = $this->quote->getShippingAddress()->getGroupedAllShippingRates();

         $isIngridEnabled = $this->qliroConfig->isIngridEnabled($this->quote->getStoreId());
         foreach ($rateGroups as $group) {
             /** @var Rate $rate */
             foreach ($group as $rate) {
                 if (substr($rate->getCode(), -6) === '_error') {
                     continue;
                 }

                 // if ingrid delivery method is enabled - make sure only this shipping method is sent to qliro
                 if ($isIngridEnabled && $rate->getCode() !== Ingrid::QLIRO_INGRID_SHIPPING_CODE) {
                     continue;
                 }

                 $this->shippingMethodBuilder->setQuote($this->quote);

                 /** @var \Magento\Store\Api\Data\StoreInterface */
                 $store = $this->storeManager->getStore();
                 $amountPrice = $store->getBaseCurrency()
                     ->convert($rate->getPrice(), $store->getCurrentCurrencyCode());
                 $rate->setPrice($amountPrice);

                 $this->shippingMethodBuilder->setShippingRate($rate);
                 $shippingMethodContainer = $this->shippingMethodBuilder->create();

                 if (!$shippingMethodContainer->getMerchantReference()) {
                     continue;
                 }

                 $shippingMethods[] = $shippingMethodContainer;
             }
         }

         return $this->reorderShippingMethods($shippingMethods);
     }

    /**
     * Reorder shipping methods to prioritize the preselected method
     *
     * Preselected shipping method used only with qliro as a payment option.
     * See $this->qliroConfig->getShowAsPaymentMethod()
     *
     * Qliro iframe uses the first provided shipping method to preselect.
     * That is why we move the preselected method to the top of the array
     *
     * @param array $shippingMethods List of shipping methods to be reordered
     * @return array Reordered list of shipping methods
     */
     protected function reorderShippingMethods(array $shippingMethods) : array
     {
         if (!count($shippingMethods) || !$this->qliroConfig->getShowAsPaymentMethod()) {
             return $shippingMethods;
         }

         $preselectedMethod = $this->quote->getShippingAddress()->getShippingMethod();
         foreach ($shippingMethods as $index => $method) {
             if (method_exists($method, 'getMerchantReference') &&
                 $method->getMerchantReference() === $preselectedMethod) {

                 $preferred = $shippingMethods[$index];
                 unset($shippingMethods[$index]);
                 array_unshift($shippingMethods, $preferred);
                 break;
             }
         }

         return array_values($shippingMethods);
     }
}

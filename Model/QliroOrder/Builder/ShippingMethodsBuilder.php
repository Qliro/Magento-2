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
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Store\Model\Information;
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
     * @var array The values the placeholder put on the address, empty when none was applied
     */
    private $presetData = [];

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
     * @var \Magento\Store\Model\Information
     */
    private $information;

    /**
     * @var \Magento\Shipping\Model\Config
     */
    private $shippingConfig;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory $shippingMethodsResponseFactory
     * @param \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder $shippingMethodBuilder
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param StoreManagerInterface $storeManager
     * @param Config $qliroConfig
     * @param LogManager|null $logManager
     * @param Information|null $information
     * @param ShippingConfig|null $shippingConfig
     */
    public function __construct(
        UpdateShippingMethodsResponseInterfaceFactory $shippingMethodsResponseFactory,
        ShippingMethodBuilder $shippingMethodBuilder,
        ManagerInterface $eventManager,
        StoreManagerInterface $storeManager,
        Config $qliroConfig,
        ?LogManager $logManager = null,
        ?Information $information = null,
        ?ShippingConfig $shippingConfig = null,
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
        $this->information = $information ?: ObjectManager::getInstance()->get(Information::class);
        $this->shippingConfig = $shippingConfig ?: ObjectManager::getInstance()->get(ShippingConfig::class);
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

        $addressBeforePreset = $this->applyPresetAddress();

        // In a finally because a carrier that throws would otherwise leave the store's own
        // address on the quote, which is the defect the restore exists for. The totals and the
        // rates collected against the placeholder are put back with it, because the restore is
        // now written to the database and a delivery price of the store's own must not be.
        try {
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
        } finally {
            $this->restorePresetAddress($addressBeforePreset);
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
     * Fill the shipping address from Store Information so the carriers have something to rate
     *
     * Only with the setting on and no postcode of the buyer's own on the quote, which is a buyer
     * Qliro has not reported an address for yet. It lives here, at the one place that rates, and
     * not where the Qliro order is created, because every rating in the request needs it:
     * `collectShippingRates()` drops the rates it finds first, so one later rating on an empty
     * address would leave the Qliro order with no methods at all.
     *
     * @return array The values to put back afterwards, empty when nothing was preset
     */
    private function applyPresetAddress(): array
    {
        $address = $this->quote->getShippingAddress();

        $this->presetData = [];

        if (!$this->qliroConfig->presetAddress() || !empty($address->getPostcode())) {
            return [];
        }

        $storeInfo = $this->information->getStoreInformationObject($this->quote->getStore());

        if (empty($storeInfo)) {
            return [];
        }

        /*
         * Only what a carrier rates on. The company and the phone were in here too, and both are
         * read elsewhere as the buyer's own: the company decides the juridical type sent to Qliro
         * and the store name printed on the company line of the order's shipping address.
         */
        $presetData = [
            'street' => sprintf(
                "%s\n%s",
                $storeInfo->getData('street_line1'),
                $storeInfo->getData('street_line2')
            ),
            'city' => $storeInfo->getData('city'),
            'postcode' => str_replace(' ', '', (string)$storeInfo->getData('postcode')),
            'region_id' => $storeInfo->getData('region_id'),
            'country_id' => $storeInfo->getData('country_id'),
            'region' => $storeInfo->getData('region'),
        ];

        /*
         * The whole row, not the six address keys. Rating the placeholder collects the address's
         * totals against the store as well, tax and discount among them wherever a rule keys on
         * the postcode or the region, and the restore is written to the database now, so anything
         * left out of the snapshot would be saved under the buyer's own empty address.
         */
        $addressBeforePreset = $address->getData();

        $address->addData($presetData);
        $this->presetData = $presetData;

        return $addressBeforePreset;
    }

    /**
     * Give the quote back the address values the preset one replaced
     *
     * The placeholder is the store's own address and the quote must not keep it: whatever is left
     * on the quote is what the order is placed with, and the store name reached the buyer's order.
     *
     * @param array $addressBeforePreset
     * @return void
     */
    private function restorePresetAddress(array $addressBeforePreset): void
    {
        // Whether the placeholder was applied, not whether the snapshot has anything in it: the
        // address of a buyer Qliro has not reported yet is empty, and that is the very case this
        // runs for, so an empty snapshot is a state to restore rather than a reason to skip
        if (empty($this->presetData)) {
            return;
        }

        $address = $this->quote->getShippingAddress();

        /*
         * Decided before the object is touched. The rating takes seconds on a carrier that calls
         * a service, and Qliro's callbacks write to the same row without a session to serialise
         * them against it, so by now the row can already hold the buyer's own address. Putting
         * the snapshot back on the object would then be enough to lose it: the create path saves
         * the quote once this returns.
         */
        $storedIsStillThePlaceholder = $address->getId() && $this->rowStillHoldsThePlaceholder($address);
        $this->forgetPreset();

        if ($address->getId() && !$storedIsStillThePlaceholder) {
            $this->takeTheStoredAddressBack($address, $addressBeforePreset);

            return;
        }

        /*
         * Explicit nulls for the placeholder's own fields. Dropping a key is not the same as
         * clearing a column: Magento builds the update from the keys the object still has, so a
         * key simply removed leaves the placeholder's value standing in the row. Only these are
         * nulled, and the totals the rating collected are left to the next collect, because
         * `quote_address` declares them NOT NULL and writing a null there depends on the column
         * carrying a default.
         */
        $restored = $addressBeforePreset;

        foreach (array_keys($this->presetData) as $key) {
            if (!array_key_exists($key, $restored)) {
                $restored[$key] = null;
            }
        }

        $address->setData($restored);

        /*
         * The rates go with the address. A delivery option rated for the store is not one the
         * buyer can be given, and leaving them on the object was enough to persist them: the
         * create path saves the quote after this runs.
         */
        $address->removeAllShippingRates();
        $address->setCollectShippingRates(true);

        if (!$address->getId()) {
            return;
        }

        /*
         * The update path saves no quote after the rating, so without this the store's own
         * address stayed in the database: the buyer's street, city and postcode were written over
         * it when Qliro reported them, the region never was, and Vajper's order 000008764 went
         * out as Stockholm 11329 in Västmanlands län.
         */
        try {
            $address->save();
        } catch (\Throwable $exception) {
            // This runs from a finally that exists to survive a carrier that throws, so a write
            // that fails here must not replace the failure it was cleaning up after
            $this->logManager->critical(
                $exception,
                ['extra' => ['quote_id' => $this->quote->getId()]]
            );
        }
    }

    /**
     * Give the object the address the row already holds, written there while the rating ran
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return void
     */
    private function takeTheStoredAddressBack($address, array $addressBeforePreset): void
    {
        try {
            $address->getResource()->load($address, $address->getId());
        } catch (\Throwable $exception) {
            // Whatever happens, the placeholder must not be what the object is left holding: the
            // create path saves the quote once this returns
            $address->addData($addressBeforePreset);
            $this->logManager->critical(
                $exception,
                ['extra' => ['quote_id' => $this->quote->getId()]]
            );
        }

        $address->removeAllShippingRates();
        $address->setCollectShippingRates(true);
    }

    /**
     * Forget the placeholder, so a later rating cannot be measured against this one's
     *
     * @return void
     */
    private function forgetPreset(): void
    {
        $this->presetData = [];
    }

    /**
     * Whether the stored address is still the placeholder this rating put there
     *
     * The rating takes seconds on a carrier that calls a service, and Qliro's own callbacks write
     * to the same row without a session to serialise them against this request. Saving the
     * snapshot blind would put the buyer's street, city and postcode back to empty seconds after
     * a callback had filled them in. Only a row that still holds the placeholder is corrected;
     * one that holds a real address has already been corrected by whoever wrote it.
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return bool
     */
    private function rowStillHoldsThePlaceholder($address): bool
    {
        if (empty($this->presetData)) {
            return false;
        }

        // The whole placeholder, not its postcode: a buyer who lives in the store's own town
        // shares that postcode, and on this merchant that is the very town the defect is about
        $columns = ['street', 'city', 'postcode', 'country_id'];

        try {
            $resource = $address->getResource();
            $connection = $resource->getConnection();
            $select = $connection->select()
                ->from($resource->getMainTable(), $columns)
                ->where('address_id = ?', (int)$address->getId());

            $stored = $connection->fetchRow($select);

            if (!is_array($stored)) {
                return false;
            }

            foreach ($columns as $column) {
                if ((string)($stored[$column] ?? '') !== (string)($this->presetData[$column] ?? '')) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $exception) {
            // Reading it is what makes the write safe, so without the read there is no write
            $this->logManager->debug(
                'Could not read the stored shipping address, leaving it as it is: ' . $exception->getMessage()
            );

            return false;
        }
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
                'store_id' => (int)$this->quote->getStoreId(),
                // Named without `postcode` in it on purpose: the redaction matches its personal
                // keys by substring, so `postcode_area` would be masked exactly as `postcode` was
                // and the one field this line exists to show would read `[redacted]` again. Two
                // characters name the region a carrier refuses without naming the buyer
                'delivery_zone' => $this->postcodeArea($shippingAddress->getPostcode()),
                'country_id' => $shippingAddress->getCountryId(),
                // Whether, not what: a carrier can require these and rate on nothing without
                // them, and the address is the buyer's own.
                'has_street' => !empty($shippingAddress->getStreetFull()),
                'has_city' => !empty($shippingAddress->getCity()),
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

        $context['extra'] += $this->describeRatingScope();

        $this->logManager->notice($message, $context);
    }

    /**
     * The first characters of a postcode, enough to place a decline without naming the buyer
     *
     * @param string|null $postcode
     * @return string|null
     */
    private function postcodeArea($postcode): ?string
    {
        $digits = preg_replace('/\s+/', '', (string)$postcode);

        return $digits === '' ? null : substr($digits, 0, 2) . '…';
    }

    /**
     * What decided the rating, for a decline that points at the carriers rather than the address
     *
     * Only for the notice: a rateable address that produced nothing is answered by the store view
     * it was rated in, the currencies of that store view and the carriers Magento had to ask. A
     * carrier reading the display currency while Magento denominates the amount it rates on in the
     * base currency yields nothing wherever those two differ, and without them in the log that
     * reads the same as a postal code nobody delivers to.
     *
     * @return array
     */
    private function describeRatingScope(): array
    {
        // A logging line must never be what breaks a decline, so nothing here is allowed to throw.
        try {
            $store = $this->storeManager->getStore($this->quote->getStoreId());

            return [
                'display_currency' => $store->getCurrentCurrencyCode(),
                'base_currency' => $store->getBaseCurrencyCode(),
                'quote_currency' => $this->quote->getQuoteCurrencyCode(),
                'active_carriers' => implode(',', array_keys($this->shippingConfig->getActiveCarriers($store))),
            ];
        } catch (\Throwable $exception) {
            return ['rating_scope_error' => $exception->getMessage()];
        }
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

                 // The quote's own store and currency, not the ones the request happens to run
                 // in: the `shippingMethods` callback carries no session, and its URL carries a
                 // store code only when `web/url/use_store` is on, which Magento ships off, so by
                 // default it resolves to the default store view. The Qliro order is created
                 // in the quote's currency, CreateRequestBuilder puts getQuoteCurrencyCode() on
                 // it, and a delivery price converted into another one is charged as if it
                 // were that currency.
                 // Store, not StoreInterface: the currency getters below live on the model.
                 /** @var \Magento\Store\Model\Store $store */
                 $store = $this->storeManager->getStore($this->quote->getStoreId());
                 // A quote that never collected totals carries no currency code, and converting
                 // into an empty one throws rather than declines.
                 $quoteCurrencyCode = $this->quote->getQuoteCurrencyCode()
                     ?: $store->getDefaultCurrencyCode();
                 $amountPrice = $store->getBaseCurrency()->convert($rate->getPrice(), $quoteCurrencyCode);
                 $rate->setPrice($amountPrice);

                 $this->shippingMethodBuilder->setShippingRate($rate);
                 $shippingMethodContainer = $this->shippingMethodBuilder->create();

                 if (!$shippingMethodContainer->getMerchantReference()) {
                     continue;
                 }

                 $shippingMethods[] = $shippingMethodContainer;
             }
         }

         return $this->filterToSelectedShippingMethod($this->reorderShippingMethods($shippingMethods));
     }

    /**
     * Reduce the list to the method the native checkout already chose, in the iframe mode
     *
     * The native checkout owns the delivery choice in that mode, so a second picker inside the
     * iframe would let the buyer move the order off the method Magento rated. A single entry
     * leaves the iframe nothing to pick and still carries the cost, which travels only on
     * AvailableShippingMethods and has no line of its own.
     *
     * Every caller of this builder passes through here, the create request and the update alike,
     * so the two cannot disagree about what the iframe is allowed to show.
     *
     * The list is returned untouched when it is empty, when nothing is selected yet, or when the
     * selection is not in it. The cost rides on this list, so an empty or wrong one would drop the
     * delivery cost from the Qliro total, which is worse than showing a picker.
     *
     * @param array $shippingMethods
     * @return array
     */
     protected function filterToSelectedShippingMethod(array $shippingMethods): array
     {
         if (!count($shippingMethods) || !$this->qliroConfig->isEmbeddedIframeMode($this->quote->getStoreId())) {
             return $shippingMethods;
         }

         $selected = $this->quote->getShippingAddress()->getShippingMethod();
         if (empty($selected)) {
             return $shippingMethods;
         }

         foreach ($shippingMethods as $method) {
             if (method_exists($method, 'getMerchantReference') && $method->getMerchantReference() === $selected) {
                 return [$method];
             }
         }

         $this->logManager->debug(
             'Iframe mode: the selected delivery method is not in the rated list, sending the full list',
             ['extra' => ['selected' => $selected]]
         );

         return $shippingMethods;
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

<?php

declare(strict_types=1);

namespace Qliro\QliroOne\ViewModel\Adminhtml\Order\View;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order\AddressFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Model\Carrier\Unifaun;
use Qliro\QliroOne\Model\Carrier\Ingrid;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Management;

/**
 * View Model for extra Shipping Address info display in Admin Order view
 */
class Shipping implements \Magento\Framework\View\Element\Block\ArgumentInterface
{
    /**
     * @var DataObjectFactory
     */
    private DataObjectFactory $dataObjectFactory;

    /**
     * @var AddressFactory
     */
    private AddressFactory $addressFactory;

    /**
     * @var LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepo;

    /**
     * @var Management
     */
    private Management $orderManagement;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepo;

    /**
     * @var DataObject|null
     */
    private ?DataObject $locationObj = null;

    /**
     * @var Address|null
     */
    private ?Address $sourcedAddress = null;

    public function __construct(
        DataObjectFactory $dataObjectFactory,
        AddressFactory $addressFactory,
        LinkRepositoryInterface $linkRepo,
        Management $orderManagement,
        OrderRepositoryInterface $orderRepo
    ) {
        $this->dataObjectFactory = $dataObjectFactory;
        $this->addressFactory = $addressFactory;
        $this->linkRepo = $linkRepo;
        $this->orderManagement = $orderManagement;
        $this->orderRepo = $orderRepo;
    }

    /**
     * Gets shipping location info as a Data Object
     *
     * @param Order $order
     * @return DataObject
     */
    public function getShippingLocationInfo(Order $order): DataObject
    {
        if (!$this->locationObj) {
            $shipInfo = $this->getShippingInfoFromOrder($order);
            $locationData = [];
            if (null !== $shipInfo) {
                $agent = $shipInfo->getDataByPath('payload/agent');
                $locationData = is_array($agent) ? array_filter($agent, 'is_scalar') : [];
            }
            $this->locationObj = $this->dataObjectFactory->create()->setData($locationData);
        }
        return $this->locationObj;
    }

    /**
     * Get postal address line as string
     *
     * @param Order $order
     * @return string
     */
    public function getPostalAddressLine(Order $order): string
    {
        $address = $this->sourceShippingAddress($order);
        if (null === $address) {
            return '';
        }
        $postalLineFormat = '%s %s';
        if ($address->getCountryId()) {
            $postalLineFormat .= ', %s';
        }

        $postalLine = sprintf(
            $postalLineFormat,
            $address->getPostcode(),
            $address->getCity(),
            $address->getCountryId()
        );

        return $postalLine;
    }

    /**
     * Get street address lines as array
     *
     * @param Order $order
     * @return array
     */
    public function getStreetAddressLines(Order $order): array
    {
        $address = $this->sourceShippingAddress($order);
        return $address ? $address->getStreet() : [];
    }

    /**
     * @param Order $order
     * @return boolean
     */
    public function isQliroUnifaunShipping(Order $order): bool
    {
        return $this->getCarrierCode($order) === Unifaun::QLIRO_UNIFAUN_SHIPPING;
    }

    /**
     * @param Order $order
     * @return boolean
     */
    public function isQliroIngridShipping(Order $order): bool
    {
        return $this->getCarrierCode($order) === Ingrid::QLIRO_INGRID_SHIPPING;
    }

    /**
     * Carrier part of the shipping method, empty when the order has none
     *
     * @param Order $order
     * @return string
     */
    private function getCarrierCode(Order $order): string
    {
        return explode('_', (string)$order->getShippingMethod(), 2)[0];
    }

    /**
     * Get shipping address either from order or from shipping location info
     *
     * @param Order $order
     * @return Address|null
     */
    private function sourceShippingAddress(Order $order): ?Address
    {
        if (null === $this->sourcedAddress) {
            $address = $order->getShippingAddress();
            $locationInfo = $this->getShippingLocationInfo($order);
            if (null === $locationInfo->getZipcode()) {
                $this->sourcedAddress = $address;
                return $this->sourcedAddress;
            }
            $address = $this->createOrderAddressFromLocation($locationInfo);
            $this->sourcedAddress = $address;
        }
        return $this->sourcedAddress;
    }

    /**
     * @param Order $order
     * @return DataObject|null
     */
    private function getShippingInfoFromOrder(Order $order): ?DataObject
    {
        if (!$this->isQliroUnifaunShipping($order)) {
            return null;
        }

        $qliroShippingInfo = $order->getPayment()?->getAdditionalInformation()['qliroone_shipping_info'] ?? [];
        if (!is_array($qliroShippingInfo) || count($qliroShippingInfo) < 1) {
            return null;
        }
        return $this->dataObjectFactory->create()->setData($qliroShippingInfo);
    }

    /**
     * Creates an order address object from location data object,
     *  helping us display a location in the same way as a standard address
     *
     * @param DataObject $location
     * @return Address
     */
    private function createOrderAddressFromLocation(DataObject $location): Address
    {
        $address = $this->addressFactory->create();
        $address->setCompany($location->getName());
        $address->setCountryId($location->getCountry());
        $address->setPostcode($location->getZipcode());
        $address->setCity($location->getCity());
        $street = [$location->getData('address1')];
        if (null !== $location->getData('address2')) {
            $street[] = $location->getAddress2();
        }
        $address->setStreet($street);
        return $address;
    }

    /**
     * Get Ingrid shipping info from order
     *
     * @param Order $order
     * @return array
     */
    public function getIngridShippingInfo(Order $order): array
    {
        if (!$this->isQliroIngridShipping($order)) {
            return [];
        }
        $ingridShippingInfo = $order->getPayment()?->getAdditionalInformation()['qliroone_shipping_info'] ?? [];
        $session = is_array($ingridShippingInfo) ? ($ingridShippingInfo['payload']['session'] ?? null) : null;
        if (!is_array($session)) {
            return [];
        }
        $deliveryGroup = $session['delivery_groups'][0] ?? [];
        $deliveryGroup = is_array($deliveryGroup) ? $deliveryGroup : [];
        $qliroShippingInfo = [
            'checkout_session_id' => $this->toText($session['checkout_session_id'] ?? null),
            'tos_id' => $this->toText($deliveryGroup['tos_id'] ?? null),
            'category' => $this->toText($deliveryGroup['category']['name'] ?? null),
            'carrier' => $this->toText($deliveryGroup['shipping']['carrier'] ?? null),
            'carrier_product_id' => $this->toText($deliveryGroup['shipping']['carrier_product_id'] ?? null)
        ];
        $location = $deliveryGroup['addresses']['location'] ?? null;
        if (is_array($location)) {
            $qliroShippingInfo['location'] = $this->toText($location['name'] ?? null);
            $qliroShippingInfo['pickup_point_id'] = $this->toText($location['external_id'] ?? null);
        }
        $token = $deliveryGroup['shipping']['meta']['isb.availability_token'] ?? null;
        if (isset($token)) {
            $qliroShippingInfo['instabox_availability_token'] = $this->toText($token);
        }
        $addons = $deliveryGroup['shipping']['delivery_addons'] ?? null;
        if (isset($addons)) {
            $qliroShippingInfo['delivery_addons'] = is_array($addons)
                ? implode(', ', array_filter(array_map(function ($addon) {
                    return $this->toText(is_array($addon) ? ($addon['external_addon_id'] ?? null) : $addon);
                }, $addons), 'strlen'))
                : $this->toText($addons);
        }
        return $qliroShippingInfo;
    }

    /**
     * Stored value as text, empty when it is missing or not a scalar
     *
     * @param mixed $value
     * @return string
     */
    private function toText($value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}

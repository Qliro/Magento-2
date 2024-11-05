<?php

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
                $locationData = $shipInfo->getDataByPath('payload/agent');
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
        return $address->getStreet();
    }

    /**
     * @param Order $order
     * @return boolean
     */
    public function isQliroUnifaunShipping(Order $order): bool
    {
        return (string)$order->getShippingMethod(true)->getCarrierCode() === Unifaun::QLIRO_UNIFAUN_SHIPPING;
    }

    /**
     * @param Order $order
     * @return boolean
     */
    public function isQliroIngridShipping(Order $order): bool
    {
        return (string)$order->getShippingMethod(true)->getCarrierCode() === Ingrid::QLIRO_INGRID_SHIPPING;
    }

    /**
     * Get shipping address either from order or from shipping location info
     *
     * @param Order $order
     * @return Address
     */
    private function sourceShippingAddress(Order $order): Address
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
     * @return DataObject
     */
    private function getShippingInfoFromOrder(Order $order): ?DataObject
    {
        if (!$this->isQliroUnifaunShipping($order)) {
            return null;
        }

        $qliroShippingInfo = $order->getPayment()->getAdditionalInformation()['qliroone_shipping_info'] ?? [];
        if (count($qliroShippingInfo) < 1) {
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
        $ingridShippingInfo = $order->getPayment()->getAdditionalInformation()['qliroone_shipping_info'] ?? [];
        
        if (count($ingridShippingInfo) < 1) {
            return [];
        }
        $qliroShippingInfo = [
            'checkout_session_id' => $ingridShippingInfo['payload']['session']['checkout_session_id'],
            'tos_id' => $ingridShippingInfo['payload']['session']['delivery_groups'][0]['tos_id'],
            'category' => $ingridShippingInfo['payload']['session']['delivery_groups'][0]['category']['name'],
            'carrier' => $ingridShippingInfo['payload']['session']['delivery_groups'][0]['shipping']['carrier'],
            'carrier_product_id' => $ingridShippingInfo['payload']['session']['delivery_groups'][0]['shipping']['carrier_product_id']
        ];
        if (isset($ingridShippingInfo['payload']['session']['delivery_groups'][0]['addresses']['location'])) {
            $qliroShippingInfo['location'] = $ingridShippingInfo['payload']['session']['delivery_groups'][0]['addresses']['location']['name'];
            $qliroShippingInfo['pickup_point_id'] = $ingridShippingInfo['payload']['session']['delivery_groups'][0]['addresses']['location']['external_id'];
        }
        if (isset($ingridShippingInfo['payload']['session']['delivery_groups'][0]['shipping']['meta']['isb.availability_token'])) {
            $qliroShippingInfo['instabox_availability_token'] = $ingridShippingInfo['payload']['session']['delivery_groups'][0]['shipping']['meta']['isb.availability_token'];
        }
        if (isset($ingridShippingInfo['payload']['session']['delivery_groups'][0]['shipping']['delivery_addons'])) {
            $qliroShippingInfo['delivery_addons'] = implode(', ', array_map(function($addon) {
                return $addon['external_addon_id'];
            }, $ingridShippingInfo['payload']['session']['delivery_groups'][0]['shipping']['delivery_addons']));
        }
        return $qliroShippingInfo;
    }
}

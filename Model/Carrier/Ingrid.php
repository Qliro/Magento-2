<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Magento\Quote\Api\CartRepositoryInterface;

class Ingrid extends \Magento\Shipping\Model\Carrier\AbstractCarrier implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    const QLIRO_INGRID_SHIPPING = 'qliroingrid';
    const QLIRO_INGRID_SHIPPING_CODE = self::QLIRO_INGRID_SHIPPING . '_' . self::QLIRO_INGRID_SHIPPING;

    /**
     * @var string
     */
    protected $_code = self::QLIRO_INGRID_SHIPPING;

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    protected $_rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    protected $_rateMethodFactory;
    /**
     * @var LinkRepositoryInterface
     */
    private $linkRepository;
    /**
     * @var Config
     */
    private $qliroConfig;
    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var
     */
    private $quoteId;



    /**
     * Shipping constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param LinkRepositoryInterface $linkRepository
     * @param Cart $name
     * @param CartRepositoryInterface $quoteRepository
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        LinkRepositoryInterface $linkRepository,
        CartRepositoryInterface $quoteRepository,
        Config $qliroConfig,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
        $this->linkRepository = $linkRepository;
        $this->quoteRepository = $quoteRepository;
        $this->qliroConfig = $qliroConfig;
    }

    /**
     * get allowed methods
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * @return float
     */
    private function getShippingPrice()
    {
        $quoteId = $this->quoteId;
        try {
            $link = $this->linkRepository->getByQuoteId($quoteId);
            if ($link->getIngridShippingAmount()) {
                $shippingPrice = $link->getIngridShippingAmount();
            } else {
                $configPrice = $this->getConfigData('price');
                $shippingPrice = $this->getFinalPriceWithHandlingFee($configPrice);
            }
        } catch (\Exception $exception) {
            $configPrice = $this->getConfigData('price');
            $shippingPrice = $this->getFinalPriceWithHandlingFee($configPrice);
        }

        return $shippingPrice;
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active') ||
            !$this->qliroConfig->isIngridEnabled($this->getStore())) {
            return false;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));

        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('name'));
        if(count($request->getAllItems())){
            $this->quoteId = $request->getAllItems()[0]->getQuoteId();
            $quote = $this->quoteRepository->get($this->quoteId);
            if($quote->getShippingAddress()->getShippingDescription() && strpos($quote->getShippingAddress()->getShippingDescription(), 'Ingrid - ') !== false) {
                $shipingMethod = explode(' - ', $quote->getShippingAddress()->getShippingDescription());
                $method->setCarrierTitle($shipingMethod[0]);
                $method->setMethodTitle($shipingMethod[1]);
            }
        }

        $amount = $this->getShippingPrice();

        $method->setPrice($amount);
        $method->setCost($amount);

        $result->append($method);

        return $result;
    }
}
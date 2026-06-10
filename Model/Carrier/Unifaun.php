<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface as CartRepository;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface as Logger;
use Qliro\QliroOne\Api\LinkRepositoryInterface as LinkRepository;
use Qliro\QliroOne\Model\Config;

/**
 * Clas Unifaun
 */
class Unifaun extends AbstractCarrier implements CarrierInterface
{
    const string QLIRO_UNIFAUN_SHIPPING = 'qlirounifaun';
    const string QLIRO_UNIFAUN_SHIPPING_CODE = self::QLIRO_UNIFAUN_SHIPPING . '_' . self::QLIRO_UNIFAUN_SHIPPING; // Ugly

    protected $_code = self::QLIRO_UNIFAUN_SHIPPING;

    private mixed $quoteId = null;

    /**
     * Class constructor
     *
     * @param ScopeConfig               $scopeConfig
     * @param ErrorFactory              $rateErrorFactory
     * @param Logger                    $logger
     * @param ResultFactory             $rateResultFactory
     * @param MethodFactory             $rateMethodFactory
     * @param LinkRepository            $linkRepository
     * @param CartRepository            $quoteRepository
     * @param Config                    $qliroConfig
     * @param array                     $data
     */
    public function __construct(
        ScopeConfig                     $scopeConfig,
        ErrorFactory                    $rateErrorFactory,
        Logger                          $logger,
        private readonly ResultFactory  $rateResultFactory,
        private readonly MethodFactory  $rateMethodFactory,
        private readonly LinkRepository $linkRepository,
        private readonly CartRepository $quoteRepository,
        private readonly Config         $qliroConfig,
        array                           $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * get allowed methods
     * @return array
     */
    public function getAllowedMethods(): array
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    /**
     * @return float
     */
    private function getShippingPrice(): float
    {
        try {
            $amount = $this->linkRepository->getByQuoteId($this->quoteId)->getUnifaunShippingAmount();
            // Distinguish "no value yet" (null) from a legitimate 0.0 chosen by the customer.
            // A truthiness check would treat free shipping as missing and fall back to the
            // carrier config price.
            if ($amount !== null) {
                return (float) $amount;
            }
        } catch (\Exception $exception) {
            // fall through to config price
        }

        // getConfigData() returns false|string — cast for strict-typed signature.
        return $this->getFinalPriceWithHandlingFee((float) $this->getConfigData('price'));
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     * @throws NoSuchEntityException
     */
    public function collectRates(RateRequest $request): bool|Result
    {
        if (!$this->getConfigFlag('active') ||
            !$this->qliroConfig->isUnifaunEnabled($this->getStore())) {
            return false;
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();

        /** @var Method $method */
        $method = $this->rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));

        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('name'));
        if(count($request->getAllItems())){
            $this->quoteId = $request->getAllItems()[0]->getQuoteId();
            $quote = $this->quoteRepository->get($this->quoteId);
            if($quote->getShippingAddress()->getShippingDescription() && str_contains($quote->getShippingAddress()->getShippingDescription(), 'Unifaun -')) {
                $shippingMethod = explode(' - ', $quote->getShippingAddress()->getShippingDescription());
                $method->setCarrierTitle($shippingMethod[0]);
                $method->setMethodTitle($shippingMethod[1]);
            }
        }

        $amount = $this->getShippingPrice();

        $method->setPrice($amount);
        $method->setCost($amount);

        $result->append($method);

        return $result;
    }
}

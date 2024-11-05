<?php

namespace Qliro\QliroOne\Plugin\Magento\SalesRule\Model;

use Qliro\QliroOne\Model\Config;

/**
 * Plugin for Magento\SalesRule\Model\Rule
 */
class ValidatorPlugin
{

    /**
     * @var Config
     */
    private $qliroConfig;

    /**
     * Constructor
     * @param Config $qliroConfig
     */
    public function __construct(
        Config $qliroConfig,
    ) {
        $this->qliroConfig = $qliroConfig;
    }

    /**
     * After plugin for getRules in SalesRule Validator
     *
     * @param \Magento\SalesRule\Model\Validator $subject
     * @param array $result The list of rules returned by the getRules() method
     * @param \Magento\Quote\Model\Quote\Address|null $address
     * @return array
     */
    public function afterGetRules(\Magento\SalesRule\Model\Validator $subject, $result, $address = null)
    {
        if ($address !== null) {
            $quote = $address->getQuote();
            if ($this->qliroConfig->isIngridEnabled($quote->getStoreId()) || $this->qliroConfig->isUnifaunEnabled($quote->getStoreId())) {
                foreach ($result as $rule) {
                    if ($rule instanceof \Magento\SalesRule\Model\Rule) {
                        $rule->setApplyToShipping(0);  // Disable apply_to_shipping when Ingrid or Unifaun is enabled
                    }
                }
            }
        }

        return $result;
    }
}
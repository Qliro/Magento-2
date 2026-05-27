<?php
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Magento\SalesRule\Model;

use Qliro\QliroOne\Model\Config;

/**
 * Plugin for Magento\SalesRule\Model\Rule
 */
class ValidatorPlugin
{
    /**
     * Class constructor
     *
     * @param Config $qliroConfig
     */
    public function __construct(
        private readonly Config $qliroConfig
    ) {
    }

    /**
     * After plugin for getRules in SalesRule Validator
     *
     * @param \Magento\SalesRule\Model\Validator $subject
     * @param mixed $result The list of rules returned by the getRules() method
     * @param \Magento\Quote\Model\Quote\Address|null $address
     * @return mixed
     */
    public function afterGetRules(
        \Magento\SalesRule\Model\Validator $subject,
        mixed $result,
        ?\Magento\Quote\Model\Quote\Address $address = null
    ): mixed {
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

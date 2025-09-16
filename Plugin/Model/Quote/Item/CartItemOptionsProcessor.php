<?php

namespace Qliro\QliroOne\Plugin\Model\Quote\Item;

use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Model\Quote\Item\CartItemOptionsProcessor as Subject;
use Magento\Framework\App\Request\Http;
use Qliro\QliroOne\Model\Config;

class CartItemOptionsProcessor
{
    /**
     * @param Http $http
     * @param Config $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly Config $config
    )
    {
    }

    /**
     * Plugin around CartItemOptionsProcessor::getBuyRequest.
     *
     * In Magento, getBuyRequest() builds a "buy request" object that contains
     * product options and qty data. This is normally used when updating items
     * in the cart via CartItemPersister::save(). However, on the Qliro checkout
     * page there is no possibility for the customer to change product qty or options.
     *
     * Returning null here for the Qliro checkout route ensures that
     * CartItemPersister does not call $quote->updateItem() again during
     * checkout rendering. That prevents Magento from temporarily treating
     * the operation as "existing qty + requested qty" (e.g. 1 + 1 = 2), which
     * would otherwise trigger the "Not enough items for sale" error when
     * the product has only one unit left in stock.
     *
     * @param Subject $subject The original CartItemOptionsProcessor
     * @param callable $proceed The original getBuyRequest() method
     * @param string $productType Product type identifier
     * @param CartItemInterface $cartItem The cart item being processed
     * @return \Magento\Framework\DataObject|array|null
     */
    public function aroundGetBuyRequest(Subject $subject, callable $proceed, $productType, CartItemInterface $cartItem)
    {
        if ($this->config->isActive() && $this->http->getFullActionName() == 'checkout_qliro_index') {
            return null;
        }

        return $proceed($productType, $cartItem);
    }
}

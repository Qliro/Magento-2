<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\PreventChangeInCartWhenLocked;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote as Subject;

/**
 * Class Quote
 */
class Quote extends AbstractAction
{
    /**
     * Block adding items to a quote that is locked by an in-flight Qliro payment.
     *
     * @throws LocalizedException when the quote is locked.
     */
    public function beforeAddProduct(
        Subject $subject,
        Product $product,
        mixed $request = null,
        mixed $processMode = AbstractType::PROCESS_MODE_FULL
    ): array {
        $this->isLocked($subject);
        return [$product, $request, $processMode];
    }

    /**
     * Block removing items from a locked quote (same justification as above).
     *
     * @throws LocalizedException when the quote is locked.
     */
    public function beforeRemoveItem(Subject $subject, mixed $itemId): array
    {
        $this->isLocked($subject);
        return [$itemId];
    }
}

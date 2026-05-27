<?php

/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface as SalesRuleRepository;
use Qliro\QliroOne\Api\Product\ProductNameResolverInterface;

readonly class ProductNameResolver implements ProductNameResolverInterface
{
    /**
     * Class constructor
     *
     * @param SalesRuleRepository   $ruleRepository
     */
    public function __construct(
        private SalesRuleRepository $ruleRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(OrderItemInterface|CartItemInterface $item): string
    {
        $ruleIds = $this->getRuleIds($item);

        if (!count($ruleIds)) {
            return $item->getName();
        }

        $ruleNames = $this->getRulesNames($ruleIds);

        if (!count($ruleNames)) {
            return $item->getName();
        }

        return sprintf("%s. Applied Discounts: %s", trim($item->getName()), implode(', ', $ruleNames));
    }

    /**
     * Normalize rule ids data
     *
     * @param OrderItemInterface|CartItemInterface $item
     * @return array
     */
    protected function getRuleIds(OrderItemInterface|CartItemInterface $item): array
    {
        $ruleIds = $item->getAppliedRuleIds();

        if (!$ruleIds && $item->getParentItem()) {
            $ruleIds = $item->getParentItem()->getAppliedRuleIds();
        }

        if (!$ruleIds) {
            return [];
        }

        if (!is_array($ruleIds)) {
            return explode(',', $ruleIds);
        }

        return [];
    }

    /**
     * Get rules names
     *
     * @param array $ruleIds
     * @return array
     */
    protected function getRulesNames(array $ruleIds): array
    {
        $ruleNames = [];
        foreach ($ruleIds as $ruleId) {
            try {
                $rule = $this->ruleRepository->getById($ruleId);
                $ruleNames[] = $rule->getName();
            } catch (NoSuchEntityException|LocalizedException $e) {
                continue;
            }
        }

        return $ruleNames;
    }
}

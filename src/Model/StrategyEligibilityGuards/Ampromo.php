<?php declare(strict_types=1);
/**
 * Copyright © André Flitsch. All rights reserved.
 * See license.md for license details.
 */

namespace PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\StrategyEligibilityGuardInterface;

/**
 * Class AmpromoExclusionChecker
 *
 * @package PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards
 */
class Ampromo implements StrategyEligibilityGuardInterface
{
    public function canProcess(ProductInterface|Product $product, AbstractItem $item, Rule $rule): bool
    {
        $simpleAction = $rule->getSimpleAction();
        if ($simpleAction && str_contains($simpleAction, 'ampromo')) {
            return false;
        }

        return true;
    }
}

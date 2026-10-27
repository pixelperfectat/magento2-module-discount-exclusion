<?php declare(strict_types=1);
/**
 * Copyright (c) 2026 Pixelperfect
 * Licensed under the MIT License. See the LICENSE file for details.
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

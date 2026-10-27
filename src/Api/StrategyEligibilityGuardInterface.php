<?php
declare(strict_types=1);
/**
 * Copyright (c) 2026 Pixelperfect
 * Licensed under the MIT License. See the LICENSE file for details.
 */

namespace PixelPerfect\DiscountExclusion\Api;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;

/**
 * Interface for precondition checks for discount exclusion strategies
 */
interface StrategyEligibilityGuardInterface
{
    public function canProcess(
        ProductInterface|Product $product,
        AbstractItem $item,
        Rule $rule
    ): bool;
}

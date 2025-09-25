<?php
declare(strict_types=1);
/**
 * Copyright © André Flitsch. All rights reserved.
 * See license.md for license details.
 */

namespace PixelPerfect\DiscountExclusion\Api;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Interface for precondition checks for discount exclusion strategies
 */
interface StrategyEligibilityGuardInterface
{
    public function canProcess(
        ProductInterface|Product $product,
        AbstractItem $item,
        ?string $couponCode
    ): bool;
}
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
 * Class ZeroPriceChecker
 *
 * @package PixelPerfect\DiscountExclusion\Model\Precondition
 */
class ZeroPrice implements StrategyEligibilityGuardInterface
{
    public function canProcess(ProductInterface|Product $product, AbstractItem $item, Rule $rule): bool
    {
        /** @phpstan-ignore-next-line */
        return $product->getFinalPrice() > 0;
    }
}

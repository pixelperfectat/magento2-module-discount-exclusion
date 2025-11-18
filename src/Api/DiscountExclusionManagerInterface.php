<?php
declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;

/**
 * Manager interface for handling discount exclusion strategies
 */
interface DiscountExclusionManagerInterface
{
    /**
     * Check if product should be excluded from discounts
     *
     * @param ProductInterface|Product $product
     * @param AbstractItem             $item
     * @param Rule                     $rule
     *
     * @return bool
     */
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item,
        Rule $rule
    ): bool;
}
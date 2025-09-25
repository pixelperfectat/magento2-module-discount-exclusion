<?php
declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;

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
     * @param string|null              $couponCode
     *
     * @return bool
     */
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item,
        ?string $couponCode = null
    ): bool;
}
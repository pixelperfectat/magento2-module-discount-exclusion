<?php
declare(strict_types=1);
namespace PixelPerfect\DiscountExclusion\Api;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Interface for discount exclusion strategies
 */
interface DiscountExclusionStrategyInterface
{
    /**
     * Determine if a product should be excluded from additional discounts
     *
     * @param ProductInterface|Product $product
     * @param AbstractItem $item
     * @return bool
     */
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item
    ): bool;
}
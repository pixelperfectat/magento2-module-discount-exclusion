<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Model\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionStrategyInterface;

/**
 * Strategy to exclude products with special prices from additional discounts
 */
class SpecialPriceStrategy implements DiscountExclusionStrategyInterface
{
    /**
     * @inheritDoc
     */
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem             $item
    ): bool {
        $specialPrice = null;

        // Get special price based on object type
        if ($product instanceof Product) {
            $specialPrice = $product->getSpecialPrice();
        } elseif ($product->getCustomAttribute('special_price')) {
            $specialPrice = $product->getCustomAttribute('special_price')->getValue();
        }

        // Check if product has a special price
        return $specialPrice !== null
            && $specialPrice > 0
            && $specialPrice < $product->getPrice();
    }
}
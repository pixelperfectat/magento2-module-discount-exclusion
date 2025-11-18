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
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem             $item
    ): bool {
        /** @phpstan-ignore-next-line */
        $specialPrice = $product->getSpecialPrice();
        /** @phpstan-ignore-next-line */
        $finalPrice = $product->getFinalPrice();

        // Ensure special price is active and not overridden by other mechanisms
        if ($specialPrice && $specialPrice == $finalPrice) {
            return true; // Exclude if special price applies directly
        }

        return false;
    }
}

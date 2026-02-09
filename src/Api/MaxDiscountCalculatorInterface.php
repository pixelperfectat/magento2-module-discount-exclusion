<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResult;

/**
 * Calculates the maximum discount when a bypassed rule applies to an already-discounted product.
 *
 * Instead of stacking discounts, the customer receives max(existing, rule) from the regular price.
 * Only the difference is applied as an additional cart-rule discount.
 */
interface MaxDiscountCalculatorInterface
{
    /**
     * Calculate the max-discount result for a bypassed rule.
     *
     * @param ProductInterface|Product $product The product (with prices loaded)
     * @param Rule                     $rule    The cart price rule being evaluated
     * @param float                    $qty     Item quantity in the cart
     *
     * @return BypassResult
     */
    public function calculate(ProductInterface|Product $product, Rule $rule, float $qty): BypassResult;
}

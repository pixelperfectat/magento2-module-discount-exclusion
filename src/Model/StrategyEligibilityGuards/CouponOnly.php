<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\StrategyEligibilityGuardInterface;

/**
 * Guard that only allows exclusion logic for coupon-based rules
 *
 * Automatic cart rules (no coupon required) should not be blocked by this module.
 * Only rules that require a coupon code should have exclusion logic applied.
 */
class CouponOnly implements StrategyEligibilityGuardInterface
{
    public function canProcess(
        ProductInterface|Product $product,
        AbstractItem $item,
        Rule $rule
    ): bool {
        $couponType = (int) $rule->getCouponType();

        // Rule::COUPON_TYPE_NO_COUPON = 1 (no coupon needed - automatic rule)
        // Rule::COUPON_TYPE_SPECIFIC = 2 (specific coupon required)
        // Rule::COUPON_TYPE_AUTO = 3 (auto-generated coupons)
        //
        // Only apply exclusion logic to rules that require a coupon.
        // Automatic rules (free shipping, auto-discounts) should proceed normally.
        return $couponType !== Rule::COUPON_TYPE_NO_COUPON;
    }
}

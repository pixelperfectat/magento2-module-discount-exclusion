<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\StrategyEligibilityGuardInterface;

/**
 * Guard that skips exclusion logic when a rule has bypass enabled
 *
 * When bypass_discount_exclusion is set on a rule, the discount applies
 * even to products already discounted by special prices or catalog rules.
 */
class BypassExclusion implements StrategyEligibilityGuardInterface
{
    public function canProcess(
        ProductInterface|Product $product,
        AbstractItem $item,
        Rule $rule
    ): bool {
        return !((bool) $rule->getData('bypass_discount_exclusion'));
    }
}

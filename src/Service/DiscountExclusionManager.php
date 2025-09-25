<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionStrategyInterface;
use PixelPerfect\DiscountExclusion\Api\StrategyEligibilityGuardInterface;

/**
 * Manages discount exclusion strategies and precondition checks.
 */
class DiscountExclusionManager implements DiscountExclusionManagerInterface
{
    /**
     * @param DiscountExclusionStrategyInterface[] $strategies
     * @param StrategyEligibilityGuardInterface[]  $strategyEligibilityGuards
     */
    public function __construct(
        private readonly array $strategies = [],
        private readonly array $strategyEligibilityGuards = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item,
        ?string $couponCode = null
    ): bool {
        // Evaluate preconditions: if any fail then return false.
        foreach ($this->strategyEligibilityGuards as $strategyEligibilityGuard) {
            if (!$strategyEligibilityGuard->canProcess($product, $item, $couponCode)) {
                return false;
            }
        }

        // Evaluate strategies: if any matches, exclude from further discounts.
        foreach ($this->strategies as $strategy) {
            if ($strategy->shouldExcludeFromDiscount($product, $item)) {
                return true;
            }
        }

        return false;
    }
}
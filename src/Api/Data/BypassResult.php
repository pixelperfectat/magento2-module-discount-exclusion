<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api\Data;

/**
 * Immutable value object carrying the outcome of a max-discount calculation.
 */
readonly class BypassResult
{
    /**
     * @param BypassResultType $type               Outcome type
     * @param float            $additionalDiscount  Per-unit additional discount to apply (0 when blocked)
     * @param float            $maxAllowedTotal     Total additional discount for all units (additionalDiscount × qty)
     * @param float            $regularPrice        Product regular price
     * @param float            $currentPrice        Product final (discounted) price
     * @param float            $existingDiscountAmount Per-unit existing discount (regular − current)
     * @param float            $ruleDiscountFromRegular Per-unit rule discount calculated from regular price
     * @param float            $existingDiscountPercent Existing discount as a percentage of regular price
     * @param float            $ruleDiscountPercent  Rule discount as a percentage of regular price
     * @param float            $qty                 Item quantity
     */
    public function __construct(
        public BypassResultType $type,
        public float $additionalDiscount,
        public float $maxAllowedTotal,
        public float $regularPrice,
        public float $currentPrice,
        public float $existingDiscountAmount,
        public float $ruleDiscountFromRegular,
        public float $existingDiscountPercent,
        public float $ruleDiscountPercent,
        public float $qty,
    ) {
    }
}

<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResult;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\MaxDiscountCalculatorInterface;

/**
 * Implements max(existing, rule) discount logic for bypassed rules.
 */
class MaxDiscountCalculator implements MaxDiscountCalculatorInterface
{
    private const EPSILON = 0.001;

    /**
     * @inheritDoc
     */
    public function calculate(ProductInterface|Product $product, Rule $rule, float $qty): BypassResult
    {
        /** @var Product $product */
        $regularPrice = (float) $product->getPrice();
        $currentPrice = (float) $product->getFinalPrice();
        $simpleAction = (string) $rule->getSimpleAction();

        // cart_fixed and buy_x_get_y cannot be meaningfully capped — fall back to stacking
        if (in_array($simpleAction, [Rule::CART_FIXED_ACTION, Rule::BUY_X_GET_Y_ACTION], true)) {
            return $this->buildResult(
                BypassResultType::STACKING_FALLBACK,
                regularPrice: $regularPrice,
                currentPrice: $currentPrice,
                existingDiscount: 0.0,
                ruleDiscountFromRegular: 0.0,
                qty: $qty,
            );
        }

        // Guard against zero/negative regular price
        if ($regularPrice < self::EPSILON) {
            return $this->buildResult(
                BypassResultType::EXISTING_BETTER,
                regularPrice: $regularPrice,
                currentPrice: $currentPrice,
                existingDiscount: 0.0,
                ruleDiscountFromRegular: 0.0,
                qty: $qty,
            );
        }

        $existingDiscount = max(0.0, $regularPrice - $currentPrice);

        $ruleDiscountFromRegular = match ($simpleAction) {
            Rule::BY_PERCENT_ACTION => $regularPrice * ((float) $rule->getDiscountAmount() / 100),
            Rule::BY_FIXED_ACTION => (float) $rule->getDiscountAmount(),
            default => 0.0,
        };

        $additionalDiscount = max(0.0, $ruleDiscountFromRegular - $existingDiscount);

        $type = $additionalDiscount > self::EPSILON
            ? BypassResultType::ADJUSTED
            : BypassResultType::EXISTING_BETTER;

        return $this->buildResult(
            $type,
            regularPrice: $regularPrice,
            currentPrice: $currentPrice,
            existingDiscount: $existingDiscount,
            ruleDiscountFromRegular: $ruleDiscountFromRegular,
            qty: $qty,
            additionalDiscount: $additionalDiscount,
        );
    }

    /**
     * Build a BypassResult with computed percentage fields.
     *
     * @param BypassResultType $type
     * @param float            $regularPrice
     * @param float            $currentPrice
     * @param float            $existingDiscount
     * @param float            $ruleDiscountFromRegular
     * @param float            $qty
     * @param float            $additionalDiscount
     *
     * @return BypassResult
     */
    private function buildResult(
        BypassResultType $type,
        float $regularPrice,
        float $currentPrice,
        float $existingDiscount,
        float $ruleDiscountFromRegular,
        float $qty,
        float $additionalDiscount = 0.0,
    ): BypassResult {
        $existingPercent = $regularPrice > self::EPSILON
            ? round(($existingDiscount / $regularPrice) * 100, 2)
            : 0.0;

        $rulePercent = $regularPrice > self::EPSILON
            ? round(($ruleDiscountFromRegular / $regularPrice) * 100, 2)
            : 0.0;

        return new BypassResult(
            type: $type,
            additionalDiscount: round($additionalDiscount, 4),
            maxAllowedTotal: round($additionalDiscount * $qty, 4),
            regularPrice: $regularPrice,
            currentPrice: $currentPrice,
            existingDiscountAmount: round($existingDiscount, 4),
            ruleDiscountFromRegular: round($ruleDiscountFromRegular, 4),
            existingDiscountPercent: $existingPercent,
            ruleDiscountPercent: $rulePercent,
            qty: $qty,
        );
    }
}

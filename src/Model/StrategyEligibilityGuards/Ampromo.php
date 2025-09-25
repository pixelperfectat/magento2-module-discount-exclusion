<?php declare(strict_types=1);
/**
 * Copyright © André Flitsch. All rights reserved.
 * See license.md for license details.
 */

namespace PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Api\Data\CouponInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Model\Coupon;
use PixelPerfect\DiscountExclusion\Api\StrategyEligibilityGuardInterface;

/**
 * Class AmpromoExclusionChecker
 *
 * @package PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards
 */
class Ampromo implements StrategyEligibilityGuardInterface
{

    public function __construct(
        private readonly CouponInterface  $coupon,
        private readonly RuleRepositoryInterface $ruleRepository
    ) {
    }

    public function canProcess(ProductInterface|Product $product, AbstractItem $item, ?string $couponCode): bool
    {
        if ($couponCode) {
            /** @var CouponInterface|Coupon $coupon */
            $coupon = $this->coupon->loadByCode($couponCode);
            $rule   = $this->ruleRepository->getById($coupon->getRuleId());

            $simpleAction = $rule->getSimpleAction();
            if ($simpleAction && str_contains($simpleAction, 'ampromo')) {
                return false;
            }
        }

        return true;
    }
}

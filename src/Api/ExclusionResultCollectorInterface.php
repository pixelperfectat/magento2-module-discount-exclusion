<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Collects exclusion results during quote processing for later message display
 */
interface ExclusionResultCollectorInterface
{
    /**
     * Add an excluded item to the collector
     *
     * @param AbstractItem $item
     * @param string       $reason
     * @param string       $couponCode
     */
    public function addExcludedItem(AbstractItem $item, string $reason, string $couponCode): void;

    /**
     * Check if there are excluded items for a coupon
     */
    public function hasExcludedItems(string $couponCode): bool;

    /**
     * Check if there are any excluded items at all
     */
    public function hasAnyExcludedItems(): bool;

    /**
     * Get all excluded items for a coupon code
     *
     * @return array<string, array{name: string, reason: string}>
     */
    public function getExcludedItems(string $couponCode): array;

    /**
     * Get all coupon codes that have excluded items
     *
     * @return string[]
     */
    public function getCouponCodes(): array;

    /**
     * Clear all collected results
     */
    public function clear(): void;
}

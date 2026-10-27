<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

use Magento\Quote\Model\Quote\Item\AbstractItem;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;

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
     *
     * @deprecated Removal planned for 2.0.0. Not used by the module.
     * @see self::hasExcludedItems()
     */
    public function hasAnyExcludedItems(): bool;

    /**
     * Get all excluded items for a coupon code
     *
     * @return array<int|string, array{name: string, reason: string}>
     */
    public function getExcludedItems(string $couponCode): array;

    /**
     * Get all coupon codes that have excluded items
     *
     * @deprecated Removal planned for 2.0.0. Not used by the module.
     * @see self::hasExcludedItems()
     * @return string[]
     */
    public function getCouponCodes(): array;

    /**
     * Add a bypassed item (max-discount logic applied) to the collector
     *
     * @param AbstractItem              $item
     * @param BypassResultType          $type
     * @param string                    $couponCode
     * @param array<string, float|string> $messageParams Parameters for message rendering
     */
    public function addBypassedItem(
        AbstractItem $item,
        BypassResultType $type,
        string $couponCode,
        array $messageParams = [],
    ): void;

    /**
     * Check if there are bypassed items for a coupon
     */
    public function hasBypassedItems(string $couponCode): bool;

    /**
     * Check if there are any bypassed items at all
     *
     * @deprecated Removal planned for 2.0.0. Not used by the module.
     * @see self::hasBypassedItems()
     */
    public function hasAnyBypassedItems(): bool;

    /**
     * Get all bypassed items for a coupon code
     *
     * @return array<int|string, array{
     *     name: string,
     *     type: BypassResultType,
     *     messageParams: array<string, float|string>
     * }>
     */
    public function getBypassedItems(string $couponCode): array;

    /**
     * Clear all collected results
     */
    public function clear(): void;
}

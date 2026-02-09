<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Quote\Model\Quote\Item\AbstractItem;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use Psr\Log\LoggerInterface;

/**
 * Collects exclusion results during quote processing.
 * This is a shared (singleton) service that collects data across all items.
 */
class ExclusionResultCollector implements ExclusionResultCollectorInterface
{
    /**
     * @var array<string, array<string, array{name: string, reason: string}>>
     */
    private array $excludedItems = [];

    /**
     * @var array<string, array<string, array{name: string, type: BypassResultType, messageParams: array<string, float|string>}>>
     */
    private array $bypassedItems = [];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function addExcludedItem(AbstractItem $item, string $reason, string $couponCode): void
    {
        $productId = (string) $item->getProduct()->getId();
        $productName = (string) $item->getProduct()->getName();

        // Avoid duplicates for the same product
        if (isset($this->excludedItems[$couponCode][$productId])) {
            return;
        }

        $this->excludedItems[$couponCode][$productId] = [
            'name' => $productName,
            'reason' => $reason,
        ];

        $this->logger->debug('DiscountExclusion: Collector - Added excluded item', [
            'product_id' => $productId,
            'product_name' => $productName,
            'reason' => $reason,
            'coupon_code' => $couponCode,
            'total_excluded' => count($this->excludedItems[$couponCode]),
        ]);
    }

    public function hasExcludedItems(string $couponCode): bool
    {
        return !empty($this->excludedItems[$couponCode]);
    }

    public function hasAnyExcludedItems(): bool
    {
        return !empty($this->excludedItems);
    }

    public function getExcludedItems(string $couponCode): array
    {
        return $this->excludedItems[$couponCode] ?? [];
    }

    public function getCouponCodes(): array
    {
        return array_keys($this->excludedItems);
    }

    /**
     * @inheritDoc
     */
    public function addBypassedItem(
        AbstractItem $item,
        BypassResultType $type,
        string $couponCode,
        array $messageParams = [],
    ): void {
        $productId = (string) $item->getProduct()->getId();
        $productName = (string) $item->getProduct()->getName();

        // Avoid duplicates for the same product
        if (isset($this->bypassedItems[$couponCode][$productId])) {
            return;
        }

        $this->bypassedItems[$couponCode][$productId] = [
            'name' => $productName,
            'type' => $type,
            'messageParams' => $messageParams,
        ];

        $this->logger->debug('DiscountExclusion: Collector - Added bypassed item', [
            'product_id' => $productId,
            'product_name' => $productName,
            'type' => $type->value,
            'coupon_code' => $couponCode,
            'total_bypassed' => count($this->bypassedItems[$couponCode]),
        ]);
    }

    public function hasBypassedItems(string $couponCode): bool
    {
        return !empty($this->bypassedItems[$couponCode]);
    }

    public function hasAnyBypassedItems(): bool
    {
        return !empty($this->bypassedItems);
    }

    public function getBypassedItems(string $couponCode): array
    {
        return $this->bypassedItems[$couponCode] ?? [];
    }

    public function clear(): void
    {
        $this->logger->debug('DiscountExclusion: Collector - Clearing all results', [
            'had_items' => !empty($this->excludedItems),
            'had_bypassed' => !empty($this->bypassedItems),
        ]);
        $this->excludedItems = [];
        $this->bypassedItems = [];
    }
}

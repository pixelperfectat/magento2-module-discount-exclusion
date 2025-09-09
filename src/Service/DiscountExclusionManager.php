<?php declare(strict_types=1);
namespace PixelPerfect\DiscountExclusion\Service;

use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionStrategyInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Manages discount exclusion strategies
 */
class DiscountExclusionManager implements DiscountExclusionManagerInterface
{
    /**
     * @var DiscountExclusionStrategyInterface[]
     */
    private array $strategies;

    /**
     * DiscountExclusionManager constructor.
     *
     * @param DiscountExclusionStrategyInterface[] $strategies
     */
    public function __construct(array $strategies = [])
    {
        $this->strategies = $strategies;
    }

    /**
     * @inheritDoc
     */
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item
    ): bool {
        foreach ($this->strategies as $strategy) {
            if ($strategy->shouldExcludeFromDiscount($product, $item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function addStrategy(DiscountExclusionStrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }
}
<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Model\Validator;

use Laminas\Validator\ValidatorInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;

class DiscountValidator implements ValidatorInterface
{
    /**
     * @var array<string, string>
     */
    private array $messages = [];

    public function __construct(
        private readonly DiscountExclusionManagerInterface $discountExclusionManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @inheritDoc
     */
    public function isValid($value): bool
    {
        // Clear previous messages
        $this->messages = [];

        if (!$value instanceof AbstractItem) {
            return true;
        }

        // Check if the item is a child of a complex item
        /** @var Item $value */
        if ($value->getParentItem()) {
            return false;
        }

        // either a simple item or a complex item
        $product = $value->getProduct();
        $children = $value->getChildren();
        if (count($children) > 0 && $value->getChildren()[0]->getProduct()) {
            $product = $value->getChildren()[0]->getProduct();
        }

        // Check if the product should be excluded from additional discounts
        $shouldExclude = $this->discountExclusionManager->shouldExcludeFromDiscount(
            $product,
            $value
        );

        if ($shouldExclude) {
            $this->messages['discount_exclusion'] = __(
                'Product "%1" cannot receive additional discounts because it is already discounted.',
                $value->getProduct()->getName()
            )->render();
            return false;
        }

        return true;
    }
}
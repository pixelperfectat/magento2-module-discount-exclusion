<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model;

use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use Psr\Log\LoggerInterface;

class ValidatorPlugin
{
    public function __construct(
        private readonly ConfigInterface                    $config,
        private readonly DiscountExclusionManagerInterface  $discountExclusionManager,
        private readonly ExclusionResultCollectorInterface  $resultCollector,
        private readonly LoggerInterface                    $logger
    ) {
    }

    /**
     * Around plugin for Validator::process to intercept discount validation
     *
     * @param Validator    $subject
     * @param callable     $proceed
     * @param AbstractItem $item
     * @param Rule         $rule
     *
     * @return Validator
     */
    public function aroundProcess(
        Validator $subject,
        callable $proceed,
        AbstractItem $item,
        Rule $rule
    ): Validator {
        // Early exit if module is disabled
        if (!$this->config->isEnabled($item->getStoreId())) {
            return $proceed($item, $rule);
        }

        // Skip child items of complex products
        if ($item->getParentItem()) {
            return $proceed($item, $rule);
        }

        // Get the actual product (handle configurable products by checking children)
        $product = $this->getActualProduct($item);

        // CRITICAL FIX: Get coupon code from the item's quote (same instance as controller)
        $couponCode = $this->getCouponCode($item);

        $this->logger->debug('DiscountExclusion: Processing item', [
            'product_sku' => $product->getSku(),
            'product_name' => $product->getName(),
            'rule_id' => $rule->getId(),
            'rule_name' => $rule->getName(),
            'coupon_code' => $couponCode,
            'final_price' => $product->getFinalPrice(),
            'regular_price' => $product->getPrice(),
        ]);

        // Check if the product should be excluded from additional discounts
        $shouldExclude = $this->discountExclusionManager->shouldExcludeFromDiscount(
            $product,
            $item,
            $rule
        );

        if ($shouldExclude) {
            $this->logger->info('DiscountExclusion: Blocking discount for product', [
                'product_sku' => $product->getSku(),
                'product_name' => $product->getName(),
                'rule_id' => $rule->getId(),
                'coupon_code' => $couponCode,
            ]);

            // Add to collector if we have a coupon code
            if ($couponCode !== null && $couponCode !== '') {
                $this->resultCollector->addExcludedItem(
                    $item,
                    (string) __('Product is already discounted'),
                    $couponCode
                );
            } else {
                $this->logger->warning('DiscountExclusion: No coupon code found, cannot track exclusion', [
                    'product_sku' => $product->getSku(),
                ]);
            }

            // Return subject without calling proceed - this blocks the discount
            return $subject;
        }

        $this->logger->debug('DiscountExclusion: Allowing discount for product', [
            'product_sku' => $product->getSku(),
            'rule_id' => $rule->getId(),
        ]);

        // Allow discount by calling proceed
        return $proceed($item, $rule);
    }

    /**
     * Get the actual product (handle configurable products by using child product)
     */
    private function getActualProduct(AbstractItem $item): \Magento\Catalog\Model\Product
    {
        $product = $item->getProduct();
        $children = $item->getChildren();

        if (count($children) > 0 && $children[0]->getProduct()) {
            $product = $children[0]->getProduct();
        }

        return $product;
    }

    /**
     * Get the coupon code from the CORRECT quote instance
     *
     * CRITICAL: Must get from item's quote, not from session,
     * to ensure we're using the same instance as the controller.
     * The session proxy may return a different quote instance that
     * doesn't have the coupon code set yet.
     */
    private function getCouponCode(AbstractItem $item): ?string
    {
        // Get from the item's quote - this is the SAME instance the controller uses
        $quote = $item->getQuote();
        if ($quote !== null) {
            $couponCode = $quote->getCouponCode();
            if ($couponCode !== null && $couponCode !== '') {
                return $couponCode;
            }
        }

        return null;
    }
}

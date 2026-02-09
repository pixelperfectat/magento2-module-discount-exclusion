<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResult;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Api\MaxDiscountCalculatorInterface;
use Psr\Log\LoggerInterface;

class ValidatorPlugin
{
    public function __construct(
        private readonly ConfigInterface                    $config,
        private readonly DiscountExclusionManagerInterface  $discountExclusionManager,
        private readonly ExclusionResultCollectorInterface  $resultCollector,
        private readonly MaxDiscountCalculatorInterface     $maxDiscountCalculator,
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

        // Check if this rule has bypass enabled
        $hasBypass = (bool) $rule->getData('bypass_discount_exclusion');

        if ($hasBypass) {
            return $this->handleBypass($subject, $proceed, $item, $rule, $product, $couponCode);
        }

        return $this->handleStandardExclusion($subject, $proceed, $item, $rule, $product, $couponCode);
    }

    /**
     * Handle bypass flow: max(existing, rule) logic
     *
     * @param Validator    $subject
     * @param callable     $proceed
     * @param AbstractItem $item
     * @param Rule         $rule
     * @param Product      $product
     * @param string|null  $couponCode
     *
     * @return Validator
     */
    private function handleBypass(
        Validator $subject,
        callable $proceed,
        AbstractItem $item,
        Rule $rule,
        Product $product,
        ?string $couponCode,
    ): Validator {
        // Check if product is already discounted
        $isAlreadyDiscounted = $this->discountExclusionManager->shouldExcludeFromDiscount(
            $product,
            $item,
            $rule
        );

        // Product not already discounted — apply the discount normally
        if (!$isAlreadyDiscounted) {
            $this->logger->debug('DiscountExclusion: Bypass rule on non-discounted product, proceeding normally', [
                'product_sku' => $product->getSku(),
                'rule_id' => $rule->getId(),
            ]);
            return $proceed($item, $rule);
        }

        // Product is already discounted — calculate max discount
        $bypassResult = $this->maxDiscountCalculator->calculate(
            $product,
            $rule,
            (float) $item->getQty()
        );

        $this->logger->debug('DiscountExclusion: Bypass result', [
            'product_sku' => $product->getSku(),
            'rule_id' => $rule->getId(),
            'type' => $bypassResult->type->value,
            'additional_discount' => $bypassResult->additionalDiscount,
            'max_allowed_total' => $bypassResult->maxAllowedTotal,
        ]);

        return match ($bypassResult->type) {
            BypassResultType::STACKING_FALLBACK => $proceed($item, $rule),
            BypassResultType::EXISTING_BETTER => $this->handleBypassExistingBetter(
                $subject, $item, $rule, $couponCode, $bypassResult
            ),
            BypassResultType::ADJUSTED => $this->handleBypassAdjusted(
                $subject, $proceed, $item, $rule, $couponCode, $bypassResult
            ),
        };
    }

    /**
     * Handle bypass when existing discount is better — block and record
     *
     * @param Validator    $subject
     * @param AbstractItem $item
     * @param Rule         $rule
     * @param string|null  $couponCode
     * @param BypassResult $bypassResult
     *
     * @return Validator
     */
    private function handleBypassExistingBetter(
        Validator $subject,
        AbstractItem $item,
        Rule $rule,
        ?string $couponCode,
        BypassResult $bypassResult,
    ): Validator {
        $this->logger->info('DiscountExclusion: Bypass existing better, blocking discount', [
            'product_sku' => $this->getActualProduct($item)->getSku(),
            'rule_id' => $rule->getId(),
        ]);

        if ($couponCode !== null && $couponCode !== '') {
            $this->resultCollector->addBypassedItem(
                $item,
                BypassResultType::EXISTING_BETTER,
                $couponCode,
                $this->buildMessageParams($rule, $bypassResult),
            );
        }

        return $subject;
    }

    /**
     * Handle bypass when rule discount is larger — proceed then cap
     *
     * @param Validator    $subject
     * @param callable     $proceed
     * @param AbstractItem $item
     * @param Rule         $rule
     * @param string|null  $couponCode
     * @param BypassResult $bypassResult
     *
     * @return Validator
     */
    private function handleBypassAdjusted(
        Validator $subject,
        callable $proceed,
        AbstractItem $item,
        Rule $rule,
        ?string $couponCode,
        BypassResult $bypassResult,
    ): Validator {
        // Record discount before proceed
        $discountBefore = (float) $item->getDiscountAmount();

        // Let Magento calculate the discount
        $result = $proceed($item, $rule);

        // Cap the discount to the max allowed
        $discountAfter = (float) $item->getDiscountAmount();
        $ruleDiscount = $discountAfter - $discountBefore;

        if ($ruleDiscount > $bypassResult->maxAllowedTotal + 0.001) {
            $cappedDiscount = $discountBefore + $bypassResult->maxAllowedTotal;
            $item->setDiscountAmount($cappedDiscount);

            $this->logger->info('DiscountExclusion: Capped bypass discount', [
                'product_sku' => $this->getActualProduct($item)->getSku(),
                'rule_id' => $rule->getId(),
                'original_discount' => $ruleDiscount,
                'capped_to' => $bypassResult->maxAllowedTotal,
            ]);
        }

        if ($couponCode !== null && $couponCode !== '') {
            $this->resultCollector->addBypassedItem(
                $item,
                BypassResultType::ADJUSTED,
                $couponCode,
                $this->buildMessageParams($rule, $bypassResult),
            );
        }

        return $result;
    }

    /**
     * Handle standard exclusion flow (no bypass)
     *
     * @param Validator    $subject
     * @param callable     $proceed
     * @param AbstractItem $item
     * @param Rule         $rule
     * @param Product      $product
     * @param string|null  $couponCode
     *
     * @return Validator
     */
    private function handleStandardExclusion(
        Validator $subject,
        callable $proceed,
        AbstractItem $item,
        Rule $rule,
        Product $product,
        ?string $couponCode,
    ): Validator {
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

            return $subject;
        }

        $this->logger->debug('DiscountExclusion: Allowing discount for product', [
            'product_sku' => $product->getSku(),
            'rule_id' => $rule->getId(),
        ]);

        return $proceed($item, $rule);
    }

    /**
     * Build message parameters from rule and bypass result
     *
     * @param Rule         $rule
     * @param BypassResult $bypassResult
     *
     * @return array<string, float|string>
     */
    private function buildMessageParams(Rule $rule, BypassResult $bypassResult): array
    {
        return [
            'simpleAction' => (string) $rule->getSimpleAction(),
            'ruleDiscountPercent' => $bypassResult->ruleDiscountPercent,
            'existingDiscountPercent' => $bypassResult->existingDiscountPercent,
            'additionalDiscountPercent' => $bypassResult->ruleDiscountPercent - $bypassResult->existingDiscountPercent,
            'ruleDiscountAmount' => $bypassResult->ruleDiscountFromRegular,
            'existingDiscountAmount' => $bypassResult->existingDiscountAmount,
            'additionalDiscountAmount' => $bypassResult->additionalDiscount,
        ];
    }

    /**
     * Get the actual product (handle configurable products by using child product)
     */
    private function getActualProduct(AbstractItem $item): Product
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

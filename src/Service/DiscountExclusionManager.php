<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionStrategyInterface;
use PixelPerfect\DiscountExclusion\Api\StrategyEligibilityGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages discount exclusion strategies and precondition checks.
 */
class DiscountExclusionManager implements DiscountExclusionManagerInterface
{
    /**
     * @param DiscountExclusionStrategyInterface[] $strategies
     * @param StrategyEligibilityGuardInterface[]  $strategyEligibilityGuards
     * @param LoggerInterface                      $logger
     */
    public function __construct(
        private readonly array $strategies = [],
        private readonly array $strategyEligibilityGuards = [],
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item,
        Rule $rule
    ): bool {
        /** @var Product $product */
        $context = [
            'product_id' => $product->getId(),
            'product_sku' => $product->getSku(),
            'final_price' => $product->getFinalPrice(),
            'regular_price' => $product->getPrice(),
            'special_price' => $product->getSpecialPrice(),
            'rule_id' => $rule->getId(),
            'rule_action' => $rule->getSimpleAction(),
        ];

        $this->log('debug', 'DiscountExclusion: Checking exclusion', $context);

        // Evaluate preconditions: if any fail then return false (skip exclusion check).
        foreach ($this->strategyEligibilityGuards as $guardName => $strategyEligibilityGuard) {
            $canProcess = $strategyEligibilityGuard->canProcess($product, $item, $rule);

            $this->log('debug', "DiscountExclusion: Guard '{$guardName}' result", [
                'can_process' => $canProcess,
                'product_sku' => $product->getSku(),
                'rule_id' => $rule->getId(),
            ]);

            if (!$canProcess) {
                $this->log('debug', "DiscountExclusion: Guard '{$guardName}' blocked - skipping exclusion check", [
                    'product_sku' => $product->getSku(),
                ]);
                return false;
            }
        }

        // Evaluate strategies: if any matches, exclude from further discounts.
        foreach ($this->strategies as $strategyName => $strategy) {
            $shouldExclude = $strategy->shouldExcludeFromDiscount($product, $item);

            $this->log('debug', "DiscountExclusion: Strategy '{$strategyName}' result", [
                'should_exclude' => $shouldExclude,
                'product_sku' => $product->getSku(),
            ]);

            if ($shouldExclude) {
                $this->log('info', "DiscountExclusion: Product excluded by '{$strategyName}'", $context);
                return true;
            }
        }

        $this->log('debug', 'DiscountExclusion: Product NOT excluded - allowing discount', [
            'product_sku' => $product->getSku(),
        ]);

        return false;
    }

    /**
     * Log a message if logger is available
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        match ($level) {
            'debug' => $this->logger->debug($message, $context),
            'info' => $this->logger->info($message, $context),
            'warning' => $this->logger->warning($message, $context),
            'error' => $this->logger->error($message, $context),
            default => $this->logger->debug($message, $context),
        };
    }
}
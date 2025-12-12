<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Service;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionStrategyInterface;
use PixelPerfect\DiscountExclusion\Api\StrategyEligibilityGuardInterface;
use PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager;
use Psr\Log\LoggerInterface;

class DiscountExclusionManagerTest extends TestCase
{
    private Product&MockObject $product;
    private AbstractItem&MockObject $item;
    private Rule&MockObject $rule;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->product = $this->createMock(Product::class);
        $this->product->method('getId')->willReturn(100);
        $this->product->method('getSku')->willReturn('TEST-SKU');
        $this->product->method('getFinalPrice')->willReturn(29.99);
        $this->product->method('getPrice')->willReturn(39.99);
        $this->product->method('getSpecialPrice')->willReturn(null);

        $this->item = $this->createMock(AbstractItem::class);

        $this->rule = $this->createMock(Rule::class);
        $this->rule->method('getId')->willReturn(1);
        $this->rule->method('getSimpleAction')->willReturn('by_percent');

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testNoStrategiesReturnsNotExcluded(): void
    {
        $manager = new DiscountExclusionManager([], [], $this->logger);

        $result = $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertFalse($result, 'With no strategies, product should not be excluded');
    }

    public function testGuardBlocksExclusionCheck(): void
    {
        $guard = $this->createMock(StrategyEligibilityGuardInterface::class);
        $guard->method('canProcess')->willReturn(false);

        $strategy = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy->expects($this->never())->method('shouldExcludeFromDiscount');

        $manager = new DiscountExclusionManager(
            ['test_strategy' => $strategy],
            ['test_guard' => $guard],
            $this->logger
        );

        $result = $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertFalse($result, 'When guard blocks, product should not be excluded');
    }

    public function testGuardAllowsExclusionCheck(): void
    {
        $guard = $this->createMock(StrategyEligibilityGuardInterface::class);
        $guard->method('canProcess')->willReturn(true);

        $strategy = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy->expects($this->once())
            ->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $manager = new DiscountExclusionManager(
            ['test_strategy' => $strategy],
            ['test_guard' => $guard],
            $this->logger
        );

        $result = $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertTrue($result, 'When guard allows and strategy excludes, product should be excluded');
    }

    public function testMultipleGuardsAllMustPass(): void
    {
        $guard1 = $this->createMock(StrategyEligibilityGuardInterface::class);
        $guard1->method('canProcess')->willReturn(true);

        $guard2 = $this->createMock(StrategyEligibilityGuardInterface::class);
        $guard2->method('canProcess')->willReturn(false);

        $strategy = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy->expects($this->never())->method('shouldExcludeFromDiscount');

        $manager = new DiscountExclusionManager(
            ['test_strategy' => $strategy],
            ['guard1' => $guard1, 'guard2' => $guard2],
            $this->logger
        );

        $result = $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertFalse($result, 'If any guard blocks, strategies should not run');
    }

    public function testFirstExcludingStrategyWins(): void
    {
        $strategy1 = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy1->method('shouldExcludeFromDiscount')->willReturn(true);

        $strategy2 = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy2->expects($this->never())->method('shouldExcludeFromDiscount');

        $manager = new DiscountExclusionManager(
            ['strategy1' => $strategy1, 'strategy2' => $strategy2],
            [],
            $this->logger
        );

        $result = $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertTrue($result, 'First excluding strategy should short-circuit');
    }

    public function testAllStrategiesCheckedIfNoneExclude(): void
    {
        $strategy1 = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy1->expects($this->once())->method('shouldExcludeFromDiscount')->willReturn(false);

        $strategy2 = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy2->expects($this->once())->method('shouldExcludeFromDiscount')->willReturn(false);

        $manager = new DiscountExclusionManager(
            ['strategy1' => $strategy1, 'strategy2' => $strategy2],
            [],
            $this->logger
        );

        $result = $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertFalse($result, 'If no strategy excludes, product should not be excluded');
    }

    public function testStrategyCanExcludeWithoutGuards(): void
    {
        $strategy = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy->method('shouldExcludeFromDiscount')->willReturn(true);

        $manager = new DiscountExclusionManager(
            ['test_strategy' => $strategy],
            [], // No guards
            $this->logger
        );

        $result = $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertTrue($result, 'Strategy can exclude without guards');
    }

    public function testLoggingWhenGuardBlocks(): void
    {
        $guard = $this->createMock(StrategyEligibilityGuardInterface::class);
        $guard->method('canProcess')->willReturn(false);

        $this->logger->expects($this->atLeastOnce())->method('debug');

        $manager = new DiscountExclusionManager(
            [],
            ['test_guard' => $guard],
            $this->logger
        );

        $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);
    }

    public function testLoggingWhenStrategyExcludes(): void
    {
        $strategy = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy->method('shouldExcludeFromDiscount')->willReturn(true);

        $this->logger->expects($this->atLeastOnce())->method('info');

        $manager = new DiscountExclusionManager(
            ['test_strategy' => $strategy],
            [],
            $this->logger
        );

        $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);
    }
}

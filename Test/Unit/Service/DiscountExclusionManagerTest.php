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

    public function testWorksWithoutALogger(): void
    {
        $strategy = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy->method('shouldExcludeFromDiscount')->willReturn(true);

        $manager = new DiscountExclusionManager(['special_price' => $strategy]);

        $this->assertTrue($manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule));
    }

    /**
     * The info line names the strategy that excluded the product; operators use it to see why.
     */
    public function testExclusionIsLoggedWithTheStrategyNameAndPrices(): void
    {
        $passing = $this->createMock(DiscountExclusionStrategyInterface::class);
        $passing->method('shouldExcludeFromDiscount')->willReturn(false);
        $excluding = $this->createMock(DiscountExclusionStrategyInterface::class);
        $excluding->method('shouldExcludeFromDiscount')->willReturn(true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with("DiscountExclusion: Product excluded by 'catalog_rule'", [
                'product_id' => 100,
                'product_sku' => 'TEST-SKU',
                'final_price' => 29.99,
                'regular_price' => 39.99,
                'special_price' => null,
                'rule_id' => 1,
                'rule_action' => 'by_percent',
            ]);

        $manager = new DiscountExclusionManager(
            ['special_price' => $passing, 'catalog_rule' => $excluding],
            [],
            $this->logger
        );

        $this->assertTrue($manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule));
    }

    /**
     * The debug trail is the documented way (README "Debugging") to see which guard or
     * strategy decided an item, so its messages and decision fields are asserted.
     */
    public function testDebugTrailNamesEachGuardAndStrategyWithItsDecision(): void
    {
        $guard = $this->createMock(StrategyEligibilityGuardInterface::class);
        $guard->method('canProcess')->willReturn(true);
        $strategy = $this->createMock(DiscountExclusionStrategyInterface::class);
        $strategy->method('shouldExcludeFromDiscount')->willReturn(false);

        $trail = [];
        $this->logger->method('debug')->willReturnCallback(
            function (string $message, array $context) use (&$trail) {
                $trail[] = [$message, $context];
            }
        );

        $manager = new DiscountExclusionManager(
            ['special_price' => $strategy],
            ['coupon_only' => $guard],
            $this->logger
        );
        $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertSame(
            [
                ['DiscountExclusion: Checking exclusion', [
                    'product_id' => 100,
                    'product_sku' => 'TEST-SKU',
                    'final_price' => 29.99,
                    'regular_price' => 39.99,
                    'special_price' => null,
                    'rule_id' => 1,
                    'rule_action' => 'by_percent',
                ]],
                ["DiscountExclusion: Guard 'coupon_only' result", [
                    'can_process' => true,
                    'product_sku' => 'TEST-SKU',
                    'rule_id' => 1,
                ]],
                ["DiscountExclusion: Strategy 'special_price' result", [
                    'should_exclude' => false,
                    'product_sku' => 'TEST-SKU',
                ]],
                ['DiscountExclusion: Product NOT excluded - allowing discount', ['product_sku' => 'TEST-SKU']],
            ],
            $trail
        );
    }

    public function testDebugTrailNamesTheGuardThatSkippedTheCheck(): void
    {
        $guard = $this->createMock(StrategyEligibilityGuardInterface::class);
        $guard->method('canProcess')->willReturn(false);

        $messages = [];
        $this->logger->method('debug')->willReturnCallback(
            function (string $message, array $context) use (&$messages) {
                $messages[$message] = $context;
            }
        );

        $manager = new DiscountExclusionManager([], ['ampromo' => $guard], $this->logger);
        $manager->shouldExcludeFromDiscount($this->product, $this->item, $this->rule);

        $this->assertSame(
            ['product_sku' => 'TEST-SKU'],
            $messages["DiscountExclusion: Guard 'ampromo' blocked - skipping exclusion check"] ?? null
        );
    }
}

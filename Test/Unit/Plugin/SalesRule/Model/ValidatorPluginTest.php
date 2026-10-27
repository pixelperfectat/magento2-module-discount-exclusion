<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Plugin\SalesRule\Model;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResult;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Api\MaxDiscountCalculatorInterface;
use PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\ValidatorPlugin;
use PixelPerfect\DiscountExclusion\Service\MaxDiscountCalculator;
use Psr\Log\LoggerInterface;

class ValidatorPluginTest extends TestCase
{
    private ValidatorPlugin $plugin;
    private ConfigInterface&MockObject $config;
    private DiscountExclusionManagerInterface&MockObject $discountExclusionManager;
    private ExclusionResultCollectorInterface&MockObject $resultCollector;
    private MaxDiscountCalculatorInterface&MockObject $maxDiscountCalculator;
    private LoggerInterface&MockObject $logger;
    private Validator&MockObject $validator;
    private AbstractItem&MockObject $item;
    private Rule&MockObject $rule;
    private Quote&MockObject $quote;
    private Product&MockObject $product;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->discountExclusionManager = $this->createMock(DiscountExclusionManagerInterface::class);
        $this->resultCollector = $this->createMock(ExclusionResultCollectorInterface::class);
        $this->maxDiscountCalculator = $this->createMock(MaxDiscountCalculatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->validator = $this->createMock(Validator::class);

        $this->product = $this->createMock(Product::class);
        $this->product->method('getSku')->willReturn('TEST-SKU');
        $this->product->method('getName')->willReturn('Test Product');
        $this->product->method('getFinalPrice')->willReturn(29.99);
        $this->product->method('getPrice')->willReturn(39.99);
        $this->product->method('getId')->willReturn('42');

        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponCode'])
            ->getMock();

        $this->item = $this->getMockForAbstractClass(
            AbstractItem::class,
            [],
            '',
            false,
            true,
            true,
            ['getStoreId', 'getProduct', 'getQuote', 'getChildren', 'getParentItem', 'getQty', 'getDiscountAmount', 'setDiscountAmount', 'getBaseDiscountAmount', 'setBaseDiscountAmount']
        );
        $this->item->method('getStoreId')->willReturn(1);
        $this->item->method('getProduct')->willReturn($this->product);
        $this->item->method('getQuote')->willReturn($this->quote);
        $this->item->method('getChildren')->willReturn([]);
        $this->item->method('getQty')->willReturn(1.0);

        $this->rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->addMethods(['getName', 'getDiscountAmount'])
            ->onlyMethods(['getId', 'getData', 'getSimpleAction'])
            ->getMock();
        $this->rule->method('getId')->willReturn(1);
        $this->rule->method('getName')->willReturn('Test Rule');
        $this->rule->method('getSimpleAction')->willReturn(Rule::BY_PERCENT_ACTION);
        $this->rule->method('getDiscountAmount')->willReturn(30.0);

        $this->plugin = new ValidatorPlugin(
            $this->config,
            $this->discountExclusionManager,
            $this->resultCollector,
            $this->maxDiscountCalculator,
            $this->logger
        );
    }

    public function testModuleDisabledCallsProceed(): void
    {
        $this->config->method('isEnabled')->with(1)->willReturn(false);

        $proceeded = false;
        $proceed = function ($item, $rule) use (&$proceeded) {
            $proceeded = true;
            return $this->validator;
        };

        $result = $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);

        $this->assertTrue($proceeded, 'Proceed should be called when module is disabled');
        $this->assertSame($this->validator, $result);
    }

    public function testChildItemCallsProceed(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $parentItem = $this->getMockForAbstractClass(AbstractItem::class, [], '', false);

        $childItem = $this->getMockForAbstractClass(
            AbstractItem::class,
            [],
            '',
            false,
            true,
            true,
            ['getStoreId', 'getParentItem']
        );
        $childItem->method('getParentItem')->willReturn($parentItem);
        $childItem->method('getStoreId')->willReturn(1);

        $proceeded = false;
        $proceed = function ($item, $rule) use (&$proceeded) {
            $proceeded = true;
            return $this->validator;
        };

        $result = $this->plugin->aroundProcess($this->validator, $proceed, $childItem, $this->rule);

        $this->assertTrue($proceeded, 'Proceed should be called for child items');
    }

    public function testProductExcludedBlocksDiscount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('TESTCODE');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(0);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $proceeded = false;
        $proceed = function ($item, $rule) use (&$proceeded) {
            $proceeded = true;
            return $this->validator;
        };

        $result = $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);

        $this->assertFalse($proceeded, 'Proceed should NOT be called when product is excluded');
        $this->assertSame($this->validator, $result);
        $this->assertTrue($this->item->getData('pp_discount_excluded'));
        $this->assertSame('standard', $this->item->getData('pp_discount_exclusion_reason'));
    }

    public function testProductNotExcludedAllowsDiscount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('TESTCODE');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(0);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(false);

        $proceeded = false;
        $proceed = function ($item, $rule) use (&$proceeded) {
            $proceeded = true;
            return $this->validator;
        };

        $result = $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);

        $this->assertTrue($proceeded, 'Proceed should be called when product is not excluded');
    }

    public function testExcludedItemAddedToCollectorWithCoupon(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('TESTCODE');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(0);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $this->resultCollector->expects($this->once())
            ->method('addExcludedItem')
            ->with(
                $this->item,
                $this->stringContains('discounted'),
                'TESTCODE'
            );

        $proceed = fn($item, $rule) => $this->validator;

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);
    }

    public function testExcludedItemNotAddedToCollectorWithoutCoupon(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn(null);
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(0);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $this->resultCollector->expects($this->never())
            ->method('addExcludedItem');

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $proceed = fn($item, $rule) => $this->validator;

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);
    }

    public function testConfigurableProductUsesChildProduct(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->quote->method('getCouponCode')->willReturn('TESTCODE');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(0);

        // Create child product
        $childProduct = $this->createMock(Product::class);
        $childProduct->method('getSku')->willReturn('CHILD-SKU');
        $childProduct->method('getName')->willReturn('Child Product');
        $childProduct->method('getFinalPrice')->willReturn(19.99);
        $childProduct->method('getPrice')->willReturn(29.99);

        // Create child item
        $childItem = $this->getMockForAbstractClass(
            AbstractItem::class,
            [],
            '',
            false,
            true,
            true,
            ['getProduct']
        );
        $childItem->method('getProduct')->willReturn($childProduct);

        // Parent item returns child
        $parentItem = $this->getMockForAbstractClass(
            AbstractItem::class,
            [],
            '',
            false,
            true,
            true,
            ['getStoreId', 'getProduct', 'getQuote', 'getChildren', 'getParentItem']
        );
        $parentItem->method('getStoreId')->willReturn(1);
        $parentItem->method('getProduct')->willReturn($this->product);
        $parentItem->method('getQuote')->willReturn($this->quote);
        $parentItem->method('getChildren')->willReturn([$childItem]);
        $parentItem->method('getParentItem')->willReturn(null);

        // Expect manager to be called with child product
        $this->discountExclusionManager->expects($this->once())
            ->method('shouldExcludeFromDiscount')
            ->with(
                $childProduct,
                $parentItem,
                $this->rule
            )
            ->willReturn(false);

        $proceed = fn($item, $rule) => $this->validator;

        $this->plugin->aroundProcess($this->validator, $proceed, $parentItem, $this->rule);
    }

    public function testDebugLoggingWhenProcessingItem(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('TESTCODE');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(0);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(false);

        // Verify debug is called at least twice (Processing item + Allowing discount)
        $this->logger->expects($this->atLeast(2))
            ->method('debug');

        $proceed = fn($item, $rule) => $this->validator;

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);
    }

    public function testInfoLoggingWhenBlockingDiscount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('TESTCODE');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(0);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('Blocking discount'), $this->anything());

        $proceed = fn($item, $rule) => $this->validator;

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);
    }

    public function testEmptyCouponCodeTreatedAsNoCoupon(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(0);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $this->resultCollector->expects($this->never())
            ->method('addExcludedItem');

        $proceed = fn($item, $rule) => $this->validator;

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);
    }

    // --- Bypass tests ---

    public function testBypassOnNonDiscountedProductCallsProceed(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('BYPASS30');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(1);

        // Product is NOT already discounted
        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(false);

        // Calculator should NOT be called
        $this->maxDiscountCalculator->expects($this->never())
            ->method('calculate');

        $proceeded = false;
        $proceed = function ($item, $rule) use (&$proceeded) {
            $proceeded = true;
            return $this->validator;
        };

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);

        $this->assertTrue($proceeded, 'Proceed should be called for non-discounted product with bypass');
    }

    public function testBypassAdjustedCapsDiscount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('BYPASS30');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(1);

        // Product IS already discounted
        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        // Calculator returns ADJUSTED with €5 per unit, 1 unit
        $bypassResult = new BypassResult(
            type: BypassResultType::ADJUSTED,
            additionalDiscount: 5.0,
            maxAllowedTotal: 5.0,
            regularPrice: 100.0,
            currentPrice: 75.0,
            existingDiscountAmount: 25.0,
            ruleDiscountFromRegular: 30.0,
            existingDiscountPercent: 25.0,
            ruleDiscountPercent: 30.0,
            qty: 1.0,
        );
        $this->maxDiscountCalculator->method('calculate')->willReturn($bypassResult);

        // Simulate: discount was 0 before, Magento sets it to 22.50 (30% of €75)
        $discountSequence = [0.0]; // getDiscountAmount returns 0 first
        $this->item->method('getDiscountAmount')
            ->willReturnCallback(function () use (&$discountSequence) {
                return array_shift($discountSequence) ?? 22.50;
            });

        $this->item->expects($this->once())
            ->method('setDiscountAmount')
            ->with(5.0); // capped: 0 + 5 = 5

        $proceeded = false;
        $proceed = function ($item, $rule) use (&$proceeded) {
            $proceeded = true;
            return $this->validator;
        };

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);

        $this->assertTrue($proceeded, 'Proceed should be called for adjusted bypass');
        $this->assertTrue($this->item->getData('pp_discount_bypass_adjusted'));
        $params = $this->item->getData('pp_discount_exclusion_params');
        $this->assertIsArray($params);
        $this->assertSame(30.0, $params['ruleDiscountPercent']);
        $this->assertSame(25.0, $params['existingDiscountPercent']);
    }

    public function testBypassAdjustedCapsBaseDiscountAmount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('BYPASS30');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(1);
        $this->discountExclusionManager->method('shouldExcludeFromDiscount')->willReturn(true);

        $bypassResult = new BypassResult(
            type: BypassResultType::ADJUSTED,
            additionalDiscount: 5.0,
            maxAllowedTotal: 5.0,
            regularPrice: 100.0,
            currentPrice: 75.0,
            existingDiscountAmount: 25.0,
            ruleDiscountFromRegular: 30.0,
            existingDiscountPercent: 25.0,
            ruleDiscountPercent: 30.0,
            qty: 1.0,
        );
        $this->maxDiscountCalculator->method('calculate')->willReturn($bypassResult);

        $discountSequence = [0.0];
        $this->item->method('getDiscountAmount')
            ->willReturnCallback(function () use (&$discountSequence) {
                return array_shift($discountSequence) ?? 22.50;
            });
        $this->item->method('getBaseDiscountAmount')->willReturn(1.0);

        $this->item->expects($this->once())->method('setDiscountAmount')->with(5.0);
        $this->item->expects($this->once())->method('setBaseDiscountAmount')->with(6.0);

        $proceed = function ($item, $rule) {
            return $this->validator;
        };

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);
    }

    public function testBypassExistingBetterBlocksDiscount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('BYPASS20');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(1);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $bypassResult = new BypassResult(
            type: BypassResultType::EXISTING_BETTER,
            additionalDiscount: 0.0,
            maxAllowedTotal: 0.0,
            regularPrice: 100.0,
            currentPrice: 75.0,
            existingDiscountAmount: 25.0,
            ruleDiscountFromRegular: 20.0,
            existingDiscountPercent: 25.0,
            ruleDiscountPercent: 20.0,
            qty: 1.0,
        );
        $this->maxDiscountCalculator->method('calculate')->willReturn($bypassResult);

        $proceeded = false;
        $proceed = function ($item, $rule) use (&$proceeded) {
            $proceeded = true;
            return $this->validator;
        };

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);

        $this->assertFalse($proceeded, 'Proceed should NOT be called when existing discount is better');
        $this->assertTrue($this->item->getData('pp_discount_excluded'));
        $this->assertSame('existing_better', $this->item->getData('pp_discount_exclusion_reason'));
        $params = $this->item->getData('pp_discount_exclusion_params');
        $this->assertIsArray($params);
        $this->assertSame(20.0, $params['ruleDiscountPercent']);
        $this->assertSame(25.0, $params['existingDiscountPercent']);
    }

    public function testBypassStackingFallbackCallsProceed(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('BYPASSCART');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(1);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $bypassResult = new BypassResult(
            type: BypassResultType::STACKING_FALLBACK,
            additionalDiscount: 0.0,
            maxAllowedTotal: 0.0,
            regularPrice: 100.0,
            currentPrice: 75.0,
            existingDiscountAmount: 0.0,
            ruleDiscountFromRegular: 0.0,
            existingDiscountPercent: 0.0,
            ruleDiscountPercent: 0.0,
            qty: 1.0,
        );
        $this->maxDiscountCalculator->method('calculate')->willReturn($bypassResult);

        $proceeded = false;
        $proceed = function ($item, $rule) use (&$proceeded) {
            $proceeded = true;
            return $this->validator;
        };

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);

        $this->assertTrue($proceeded, 'Proceed should be called for stacking fallback');
    }

    public function testBypassRecordsInCollector(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('BYPASS30');
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn(1);

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $bypassResult = new BypassResult(
            type: BypassResultType::EXISTING_BETTER,
            additionalDiscount: 0.0,
            maxAllowedTotal: 0.0,
            regularPrice: 100.0,
            currentPrice: 75.0,
            existingDiscountAmount: 25.0,
            ruleDiscountFromRegular: 20.0,
            existingDiscountPercent: 25.0,
            ruleDiscountPercent: 20.0,
            qty: 1.0,
        );
        $this->maxDiscountCalculator->method('calculate')->willReturn($bypassResult);

        $this->resultCollector->expects($this->once())
            ->method('addBypassedItem')
            ->with(
                $this->item,
                BypassResultType::EXISTING_BETTER,
                'BYPASS30',
                $this->callback(function (array $params) {
                    return $params['simpleAction'] === Rule::BY_PERCENT_ACTION
                        && $params['ruleDiscountPercent'] === 20.0
                        && $params['existingDiscountPercent'] === 25.0;
                })
            );

        $proceed = fn($item, $rule) => $this->validator;

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);
    }

    // --- Disabled module, product resolution ---

    public function testModuleDisabledSkipsAllExclusionLogic(): void
    {
        $this->config->method('isEnabled')->with(1)->willReturn(false);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('SAVE10');
        $this->rule->method('getData')->willReturn(1);

        $this->discountExclusionManager->expects($this->never())->method('shouldExcludeFromDiscount');
        $this->maxDiscountCalculator->expects($this->never())->method('calculate');
        $this->resultCollector->expects($this->never())->method('addExcludedItem');
        $this->resultCollector->expects($this->never())->method('addBypassedItem');

        $calls = 0;
        $proceed = function () use (&$calls) {
            $calls++;
            return $this->validator;
        };

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);

        $this->assertSame(1, $calls, 'Magento must run its own discount logic exactly once');
        $this->assertNull($this->item->getData('pp_discount_excluded'));
    }

    public function testChildWithoutProductFallsBackToTheParentProduct(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->quote->method('getCouponCode')->willReturn('SAVE10');
        $this->rule->method('getData')->willReturn(0);

        $childItem = $this->getMockForAbstractClass(AbstractItem::class, [], '', false, true, true, ['getProduct']);
        $childItem->method('getProduct')->willReturn(null);

        $parentItem = $this->createItem($this->product, 1.0, [$childItem]);

        $this->discountExclusionManager->expects($this->once())
            ->method('shouldExcludeFromDiscount')
            ->with($this->product, $parentItem, $this->rule)
            ->willReturn(false);

        $this->plugin->aroundProcess($this->validator, fn() => $this->validator, $parentItem, $this->rule);
    }

    // --- Standard exclusion: operator log lines ---

    public function testBlockedDiscountIsLoggedWithProductRuleAndCoupon(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('TESTCODE');
        $this->rule->method('getData')->willReturn(0);
        $this->discountExclusionManager->method('shouldExcludeFromDiscount')->willReturn(true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('DiscountExclusion: Blocking discount for product', [
                'product_sku' => 'TEST-SKU',
                'product_name' => 'Test Product',
                'rule_id' => 1,
                'coupon_code' => 'TESTCODE',
            ]);

        $this->plugin->aroundProcess($this->validator, fn() => $this->validator, $this->item, $this->rule);
    }

    public function testUntrackableExclusionIsLoggedAsWarningWithSku(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn(null);
        $this->rule->method('getData')->willReturn(0);
        $this->discountExclusionManager->method('shouldExcludeFromDiscount')->willReturn(true);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('DiscountExclusion: No coupon code found, cannot track exclusion', ['product_sku' => 'TEST-SKU']);

        $this->plugin->aroundProcess($this->validator, fn() => $this->validator, $this->item, $this->rule);
    }

    // --- Bypass: existing better ---

    /**
     * @dataProvider missingCouponProvider
     */
    public function testExistingBetterWithoutCouponIsNotRecorded(?string $couponCode): void
    {
        $this->givenBypassRuleOnDiscountedProduct($couponCode, $this->existingBetterResult());

        $this->resultCollector->expects($this->never())->method('addBypassedItem');

        $result = $this->plugin->aroundProcess(
            $this->validator,
            fn() => $this->fail('Magento must not apply the rule when the existing discount is better'),
            $this->item,
            $this->rule
        );

        $this->assertSame($this->validator, $result);
        $this->assertTrue($this->item->getData('pp_discount_excluded'));
    }

    public function testExistingBetterIsLoggedWithProductAndRule(): void
    {
        $this->givenBypassRuleOnDiscountedProduct('BYPASS20', $this->existingBetterResult());

        $this->logger->expects($this->once())
            ->method('info')
            ->with('DiscountExclusion: Bypass existing better, blocking discount', [
                'product_sku' => 'TEST-SKU',
                'rule_id' => 1,
            ]);

        $this->plugin->aroundProcess($this->validator, fn() => $this->validator, $this->item, $this->rule);
    }

    // --- Bypass: adjusted ---

    public function testAdjustedItemIsRecordedWithAllMessageParams(): void
    {
        $this->givenBypassRuleOnDiscountedProduct('BYPASS30', $this->adjustedResult(5.0));
        $item = $this->createItem($this->product, 1.0);

        $expectedParams = [
            'simpleAction' => Rule::BY_PERCENT_ACTION,
            'ruleDiscountPercent' => 30.0,
            'existingDiscountPercent' => 25.0,
            'additionalDiscountPercent' => 5.0,
            'ruleDiscountAmount' => 30.0,
            'existingDiscountAmount' => 25.0,
            'additionalDiscountAmount' => 5.0,
        ];

        $this->resultCollector->expects($this->once())
            ->method('addBypassedItem')
            ->with($item, BypassResultType::ADJUSTED, 'BYPASS30', $expectedParams);

        $this->plugin->aroundProcess($this->validator, $this->magentoApplies(22.5), $item, $this->rule);

        $this->assertTrue($item->getData('pp_discount_bypass_adjusted'));
        $this->assertSame($expectedParams, $item->getData('pp_discount_exclusion_params'));
    }

    /**
     * @dataProvider missingCouponProvider
     */
    public function testAdjustedWithoutCouponIsCappedButNotRecorded(?string $couponCode): void
    {
        $this->givenBypassRuleOnDiscountedProduct($couponCode, $this->adjustedResult(5.0));
        $item = $this->createItem($this->product, 1.0);

        $this->resultCollector->expects($this->never())->method('addBypassedItem');

        $this->plugin->aroundProcess($this->validator, $this->magentoApplies(22.5), $item, $this->rule);

        $this->assertSame(5.0, $item->getDiscountAmount());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingCouponProvider(): array
    {
        return [
            'no coupon' => [null],
            'empty coupon' => [''],
        ];
    }

    public function testAdjustedReturnsWhatMagentoReturned(): void
    {
        $this->givenBypassRuleOnDiscountedProduct('BYPASS30', $this->adjustedResult(5.0));
        $otherValidator = $this->createMock(Validator::class);

        $result = $this->plugin->aroundProcess(
            $this->validator,
            function (AbstractItem $item) use ($otherValidator) {
                $item->setDiscountAmount(22.5);
                return $otherValidator;
            },
            $this->createItem($this->product, 1.0),
            $this->rule
        );

        $this->assertSame($otherValidator, $result);
    }

    /**
     * Only this rule's own share of the item discount is compared with the cap. An item that
     * already carries 3.00 from an earlier rule and gets 4.00 from this one stays at 7.00,
     * because 4.00 is below the 5.00 cap.
     */
    public function testCapComparesOnlyThisRulesShareOfTheDiscount(): void
    {
        $this->givenBypassRuleOnDiscountedProduct('BYPASS30', $this->adjustedResult(5.0));
        $item = $this->createItem($this->product, 1.0);
        $item->setDiscountAmount(3.0);
        $item->setBaseDiscountAmount(3.0);

        $this->plugin->aroundProcess($this->validator, $this->magentoApplies(4.0), $item, $this->rule);

        $this->assertSame(7.0, $item->getDiscountAmount());
        $this->assertSame(7.0, $item->getBaseDiscountAmount());
    }

    /**
     * When this rule's share exceeds the cap, the cap is added on top of the earlier rules'
     * discount. See README "Known limitations": two bypass rules on one item stack this way.
     */
    public function testCapIsAddedOnTopOfEarlierRulesDiscount(): void
    {
        $this->givenBypassRuleOnDiscountedProduct('BYPASS30', $this->adjustedResult(5.0));
        $item = $this->createItem($this->product, 1.0);
        $item->setDiscountAmount(3.0);
        $item->setBaseDiscountAmount(2.0);

        $this->plugin->aroundProcess($this->validator, $this->magentoApplies(22.5), $item, $this->rule);

        $this->assertSame(8.0, $item->getDiscountAmount());
        $this->assertSame(7.0, $item->getBaseDiscountAmount());
    }

    /**
     * A rule discount within a tenth of a cent above the cap is treated as equal and left alone.
     */
    public function testDiscountWithinToleranceOfTheCapIsNotRewritten(): void
    {
        $this->givenBypassRuleOnDiscountedProduct('BYPASS30', $this->adjustedResult(5.0));
        $item = $this->createItem($this->product, 1.0);

        $this->logger->expects($this->never())->method('info');

        $this->plugin->aroundProcess($this->validator, $this->magentoApplies(5.0005), $item, $this->rule);

        $this->assertSame(5.0005, $item->getDiscountAmount());
    }

    public function testCapIsLoggedWithOriginalAndCappedAmount(): void
    {
        $this->givenBypassRuleOnDiscountedProduct('BYPASS30', $this->adjustedResult(5.0));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('DiscountExclusion: Capped bypass discount', [
                'product_sku' => 'TEST-SKU',
                'rule_id' => 1,
                'original_discount' => 22.5,
                'capped_to' => 5.0,
            ]);

        $this->plugin->aroundProcess(
            $this->validator,
            $this->magentoApplies(22.5),
            $this->createItem($this->product, 1.0),
            $this->rule
        );
    }

    /**
     * Characterization with the real calculator: the capped item discount keeps four decimals.
     *
     * Regular 19.99, catalog rule 10% (final 17.99), coupon 15% with bypass, qty 1. Magento
     * would discount 2.70 (15% of 17.99, rounded); the plugin caps the item to 0.9985, the
     * difference between 15% of the regular price (2.9985) and the existing 2.00.
     *
     * This is intentional and must not be "fixed" by rounding here: Magento rounds discounts
     * with carried deltas (SalesRule\Model\Utility::deltaRoundingFix), and naive rounding of
     * the cap would introduce drift. Do not change it without a real checkout check against a
     * payment provider's line-item totals.
     */
    public function testBypassCapIsNotRoundedToCurrencyPrecision(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn('TEST-SKU');
        $product->method('getPrice')->willReturn(19.99);
        $product->method('getFinalPrice')->willReturn(17.99);

        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->addMethods(['getDiscountAmount'])
            ->onlyMethods(['getId', 'getData', 'getSimpleAction'])
            ->getMock();
        $rule->method('getId')->willReturn(7);
        $rule->method('getData')->willReturn(1);
        $rule->method('getSimpleAction')->willReturn(Rule::BY_PERCENT_ACTION);
        $rule->method('getDiscountAmount')->willReturn(15.0);

        $this->config->method('isEnabled')->willReturn(true);
        $this->quote->method('getCouponCode')->willReturn('BYPASS15');
        $this->discountExclusionManager->method('shouldExcludeFromDiscount')->willReturn(true);

        $plugin = new ValidatorPlugin(
            $this->config,
            $this->discountExclusionManager,
            $this->resultCollector,
            new MaxDiscountCalculator(),
            $this->logger
        );
        $item = $this->createItem($product, 1.0);

        $plugin->aroundProcess($this->validator, $this->magentoApplies(2.70), $item, $rule);

        $this->assertSame(0.9985, $item->getDiscountAmount());
        $this->assertSame(0.9985, $item->getBaseDiscountAmount());
    }

    private function givenBypassRuleOnDiscountedProduct(?string $couponCode, BypassResult $result): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn($couponCode);
        $this->rule->method('getData')->with('bypass_discount_exclusion')->willReturn('1');
        $this->discountExclusionManager->method('shouldExcludeFromDiscount')->willReturn(true);
        $this->maxDiscountCalculator->method('calculate')->willReturn($result);
    }

    /**
     * Simulates Magento's RulesApplier adding this rule's discount to the item.
     */
    private function magentoApplies(float $ruleDiscount): callable
    {
        return function (AbstractItem $item) use ($ruleDiscount) {
            $item->setDiscountAmount((float) $item->getDiscountAmount() + $ruleDiscount);
            $item->setBaseDiscountAmount((float) $item->getBaseDiscountAmount() + $ruleDiscount);
            return $this->validator;
        };
    }

    /**
     * An item whose discount amounts are stored like on a real quote item.
     *
     * @param AbstractItem[] $children
     */
    private function createItem(Product $product, float $qty, array $children = []): AbstractItem&MockObject
    {
        $item = $this->getMockForAbstractClass(
            AbstractItem::class,
            [],
            '',
            false,
            true,
            true,
            ['getStoreId', 'getProduct', 'getQuote', 'getChildren', 'getParentItem', 'getQty']
        );
        $item->method('getStoreId')->willReturn(1);
        $item->method('getProduct')->willReturn($product);
        $item->method('getQuote')->willReturn($this->quote);
        $item->method('getChildren')->willReturn($children);
        $item->method('getParentItem')->willReturn(null);
        $item->method('getQty')->willReturn($qty);

        return $item;
    }

    private function adjustedResult(float $maxAllowedTotal): BypassResult
    {
        return new BypassResult(
            type: BypassResultType::ADJUSTED,
            additionalDiscount: $maxAllowedTotal,
            maxAllowedTotal: $maxAllowedTotal,
            regularPrice: 100.0,
            currentPrice: 75.0,
            existingDiscountAmount: 25.0,
            ruleDiscountFromRegular: 30.0,
            existingDiscountPercent: 25.0,
            ruleDiscountPercent: 30.0,
            qty: 1.0,
        );
    }

    private function existingBetterResult(): BypassResult
    {
        return new BypassResult(
            type: BypassResultType::EXISTING_BETTER,
            additionalDiscount: 0.0,
            maxAllowedTotal: 0.0,
            regularPrice: 100.0,
            currentPrice: 75.0,
            existingDiscountAmount: 25.0,
            ruleDiscountFromRegular: 20.0,
            existingDiscountPercent: 25.0,
            ruleDiscountPercent: 20.0,
            qty: 1.0,
        );
    }
}

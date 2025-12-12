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
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\ValidatorPlugin;
use Psr\Log\LoggerInterface;

class ValidatorPluginTest extends TestCase
{
    private ValidatorPlugin $plugin;
    private ConfigInterface&MockObject $config;
    private DiscountExclusionManagerInterface&MockObject $discountExclusionManager;
    private ExclusionResultCollectorInterface&MockObject $resultCollector;
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
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->validator = $this->createMock(Validator::class);

        $this->product = $this->createMock(Product::class);
        $this->product->method('getSku')->willReturn('TEST-SKU');
        $this->product->method('getName')->willReturn('Test Product');
        $this->product->method('getFinalPrice')->willReturn(29.99);
        $this->product->method('getPrice')->willReturn(39.99);

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
            ['getStoreId', 'getProduct', 'getQuote', 'getChildren', 'getParentItem']
        );
        $this->item->method('getStoreId')->willReturn(1);
        $this->item->method('getProduct')->willReturn($this->product);
        $this->item->method('getQuote')->willReturn($this->quote);
        $this->item->method('getChildren')->willReturn([]);

        $this->rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->addMethods(['getName'])
            ->onlyMethods(['getId'])
            ->getMock();
        $this->rule->method('getId')->willReturn(1);
        $this->rule->method('getName')->willReturn('Test Rule');

        $this->plugin = new ValidatorPlugin(
            $this->config,
            $this->discountExclusionManager,
            $this->resultCollector,
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
    }

    public function testProductNotExcludedAllowsDiscount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->item->method('getParentItem')->willReturn(null);
        $this->quote->method('getCouponCode')->willReturn('TESTCODE');

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

        $this->discountExclusionManager->method('shouldExcludeFromDiscount')
            ->willReturn(true);

        $this->resultCollector->expects($this->never())
            ->method('addExcludedItem');

        $proceed = fn($item, $rule) => $this->validator;

        $this->plugin->aroundProcess($this->validator, $proceed, $this->item, $this->rule);
    }
}

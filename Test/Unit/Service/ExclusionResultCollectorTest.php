<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Service;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Service\ExclusionResultCollector;
use Psr\Log\LoggerInterface;

class ExclusionResultCollectorTest extends TestCase
{
    private ExclusionResultCollector $collector;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->collector = new ExclusionResultCollector($this->logger);
    }

    public function testInitialStateHasNoExcludedItems(): void
    {
        $this->assertFalse($this->collector->hasAnyExcludedItems());
        $this->assertFalse($this->collector->hasExcludedItems('TEST_COUPON'));
        $this->assertEmpty($this->collector->getExcludedItems('TEST_COUPON'));
        $this->assertEmpty($this->collector->getCouponCodes());
    }

    public function testAddExcludedItem(): void
    {
        $item = $this->createQuoteItemMock('123', 'Test Product');

        $this->collector->addExcludedItem($item, 'Product is already discounted', 'SAVE10');

        $this->assertTrue($this->collector->hasAnyExcludedItems());
        $this->assertTrue($this->collector->hasExcludedItems('SAVE10'));
        $this->assertFalse($this->collector->hasExcludedItems('OTHER_COUPON'));

        $excludedItems = $this->collector->getExcludedItems('SAVE10');
        $this->assertCount(1, $excludedItems);
        $this->assertArrayHasKey('123', $excludedItems);
        $this->assertEquals('Test Product', $excludedItems['123']['name']);
        $this->assertEquals('Product is already discounted', $excludedItems['123']['reason']);
    }

    public function testAddMultipleExcludedItems(): void
    {
        $item1 = $this->createQuoteItemMock('100', 'Product A');
        $item2 = $this->createQuoteItemMock('200', 'Product B');

        $this->collector->addExcludedItem($item1, 'Reason A', 'COUPON1');
        $this->collector->addExcludedItem($item2, 'Reason B', 'COUPON1');

        $excludedItems = $this->collector->getExcludedItems('COUPON1');
        $this->assertCount(2, $excludedItems);
        $this->assertArrayHasKey('100', $excludedItems);
        $this->assertArrayHasKey('200', $excludedItems);
    }

    public function testAddExcludedItemsForDifferentCoupons(): void
    {
        $item1 = $this->createQuoteItemMock('100', 'Product A');
        $item2 = $this->createQuoteItemMock('200', 'Product B');

        $this->collector->addExcludedItem($item1, 'Reason A', 'COUPON1');
        $this->collector->addExcludedItem($item2, 'Reason B', 'COUPON2');

        $this->assertTrue($this->collector->hasExcludedItems('COUPON1'));
        $this->assertTrue($this->collector->hasExcludedItems('COUPON2'));

        $couponCodes = $this->collector->getCouponCodes();
        $this->assertCount(2, $couponCodes);
        $this->assertContains('COUPON1', $couponCodes);
        $this->assertContains('COUPON2', $couponCodes);

        $this->assertCount(1, $this->collector->getExcludedItems('COUPON1'));
        $this->assertCount(1, $this->collector->getExcludedItems('COUPON2'));
    }

    public function testDuplicateItemsAreIgnored(): void
    {
        $item = $this->createQuoteItemMock('123', 'Test Product');

        $this->collector->addExcludedItem($item, 'First reason', 'COUPON1');
        $this->collector->addExcludedItem($item, 'Second reason', 'COUPON1');

        $excludedItems = $this->collector->getExcludedItems('COUPON1');
        $this->assertCount(1, $excludedItems);
        // First reason should be preserved
        $this->assertEquals('First reason', $excludedItems['123']['reason']);
    }

    public function testSameItemCanBeExcludedForDifferentCoupons(): void
    {
        $item = $this->createQuoteItemMock('123', 'Test Product');

        $this->collector->addExcludedItem($item, 'Reason 1', 'COUPON1');
        $this->collector->addExcludedItem($item, 'Reason 2', 'COUPON2');

        $this->assertCount(1, $this->collector->getExcludedItems('COUPON1'));
        $this->assertCount(1, $this->collector->getExcludedItems('COUPON2'));
    }

    public function testClearRemovesAllItems(): void
    {
        $item1 = $this->createQuoteItemMock('100', 'Product A');
        $item2 = $this->createQuoteItemMock('200', 'Product B');

        $this->collector->addExcludedItem($item1, 'Reason A', 'COUPON1');
        $this->collector->addExcludedItem($item2, 'Reason B', 'COUPON2');

        $this->assertTrue($this->collector->hasAnyExcludedItems());

        $this->collector->clear();

        $this->assertFalse($this->collector->hasAnyExcludedItems());
        $this->assertFalse($this->collector->hasExcludedItems('COUPON1'));
        $this->assertFalse($this->collector->hasExcludedItems('COUPON2'));
        $this->assertEmpty($this->collector->getCouponCodes());
    }

    public function testGetExcludedItemsReturnsEmptyArrayForUnknownCoupon(): void
    {
        $this->assertEmpty($this->collector->getExcludedItems('UNKNOWN_COUPON'));
    }

    public function testInitialStateHasNoBypassedItems(): void
    {
        $this->assertFalse($this->collector->hasAnyBypassedItems());
        $this->assertFalse($this->collector->hasBypassedItems('TEST_COUPON'));
        $this->assertEmpty($this->collector->getBypassedItems('TEST_COUPON'));
    }

    public function testAddBypassedItem(): void
    {
        $item = $this->createQuoteItemMock('123', 'Test Product');
        $messageParams = [
            'simpleAction' => 'by_percent',
            'ruleDiscountPercent' => 30.0,
            'existingDiscountPercent' => 25.0,
            'additionalDiscountPercent' => 5.0,
        ];

        $this->collector->addBypassedItem(
            $item,
            BypassResultType::ADJUSTED,
            'SAVE30',
            $messageParams
        );

        $this->assertTrue($this->collector->hasAnyBypassedItems());
        $this->assertTrue($this->collector->hasBypassedItems('SAVE30'));
        $this->assertFalse($this->collector->hasBypassedItems('OTHER'));

        $bypassedItems = $this->collector->getBypassedItems('SAVE30');
        $this->assertCount(1, $bypassedItems);
        $this->assertArrayHasKey('123', $bypassedItems);
        $this->assertEquals('Test Product', $bypassedItems['123']['name']);
        $this->assertSame(BypassResultType::ADJUSTED, $bypassedItems['123']['type']);
        $this->assertEquals($messageParams, $bypassedItems['123']['messageParams']);
    }

    public function testAddBypassedItemExistingBetter(): void
    {
        $item = $this->createQuoteItemMock('456', 'Already Discounted Product');

        $this->collector->addBypassedItem(
            $item,
            BypassResultType::EXISTING_BETTER,
            'SAVE20',
            ['simpleAction' => 'by_percent', 'ruleDiscountPercent' => 20.0, 'existingDiscountPercent' => 25.0]
        );

        $bypassedItems = $this->collector->getBypassedItems('SAVE20');
        $this->assertSame(BypassResultType::EXISTING_BETTER, $bypassedItems['456']['type']);
    }

    public function testDuplicateBypassedItemsAreIgnored(): void
    {
        $item = $this->createQuoteItemMock('123', 'Test Product');

        $this->collector->addBypassedItem($item, BypassResultType::ADJUSTED, 'COUPON1', ['first' => 'params']);
        $this->collector->addBypassedItem($item, BypassResultType::EXISTING_BETTER, 'COUPON1', ['second' => 'params']);

        $bypassedItems = $this->collector->getBypassedItems('COUPON1');
        $this->assertCount(1, $bypassedItems);
        $this->assertSame(BypassResultType::ADJUSTED, $bypassedItems['123']['type']);
    }

    public function testGetBypassedItemsReturnsEmptyForUnknownCoupon(): void
    {
        $this->assertEmpty($this->collector->getBypassedItems('UNKNOWN'));
    }

    public function testClearResetsExcludedAndBypassedItems(): void
    {
        $item1 = $this->createQuoteItemMock('100', 'Product A');
        $item2 = $this->createQuoteItemMock('200', 'Product B');

        $this->collector->addExcludedItem($item1, 'Reason', 'COUPON1');
        $this->collector->addBypassedItem($item2, BypassResultType::ADJUSTED, 'COUPON1');

        $this->assertTrue($this->collector->hasAnyExcludedItems());
        $this->assertTrue($this->collector->hasAnyBypassedItems());

        $this->collector->clear();

        $this->assertFalse($this->collector->hasAnyExcludedItems());
        $this->assertFalse($this->collector->hasAnyBypassedItems());
        $this->assertEmpty($this->collector->getBypassedItems('COUPON1'));
    }

    /**
     * Create a mock quote item with product
     */
    private function createQuoteItemMock(string $productId, string $productName): AbstractItem&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($productId);
        $product->method('getName')->willReturn($productName);

        $item = $this->createMock(AbstractItem::class);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }
}

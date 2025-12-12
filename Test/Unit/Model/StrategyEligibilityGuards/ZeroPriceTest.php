<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Model\StrategyEligibilityGuards;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\ZeroPrice;

class ZeroPriceTest extends TestCase
{
    private ZeroPrice $guard;
    private AbstractItem&MockObject $item;
    private Rule&MockObject $rule;

    protected function setUp(): void
    {
        $this->guard = new ZeroPrice();
        $this->item = $this->createMock(AbstractItem::class);
        $this->rule = $this->createMock(Rule::class);
    }

    /**
     * @dataProvider priceProvider
     */
    public function testCanProcess(float $finalPrice, bool $expectedResult, string $description): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getFinalPrice')->willReturn($finalPrice);

        $result = $this->guard->canProcess($product, $this->item, $this->rule);

        $this->assertEquals($expectedResult, $result, $description);
    }

    /**
     * @return array<string, array{float, bool, string}>
     */
    public static function priceProvider(): array
    {
        return [
            'zero_price_should_skip' => [
                0.0,
                false,
                'Zero price products should skip exclusion logic'
            ],
            'negative_price_should_skip' => [
                -1.0,
                false,
                'Negative price products should skip exclusion logic'
            ],
            'small_positive_price_should_process' => [
                0.01,
                true,
                'Products with small positive price should process exclusion logic'
            ],
            'normal_price_should_process' => [
                19.99,
                true,
                'Products with normal price should process exclusion logic'
            ],
            'high_price_should_process' => [
                999.99,
                true,
                'Products with high price should process exclusion logic'
            ],
        ];
    }

    public function testFreeGiftProductsSkipExclusion(): void
    {
        // Free gift products (price = 0) should NOT have exclusion logic applied
        $freeProduct = $this->createMock(Product::class);
        $freeProduct->method('getFinalPrice')->willReturn(0.0);

        $result = $this->guard->canProcess($freeProduct, $this->item, $this->rule);

        $this->assertFalse($result, 'Free products should skip exclusion logic');
    }

    public function testRegularProductsAreProcessed(): void
    {
        // Regular priced products should have exclusion logic applied
        $regularProduct = $this->createMock(Product::class);
        $regularProduct->method('getFinalPrice')->willReturn(49.99);

        $result = $this->guard->canProcess($regularProduct, $this->item, $this->rule);

        $this->assertTrue($result, 'Regular priced products should be processed for exclusion');
    }
}

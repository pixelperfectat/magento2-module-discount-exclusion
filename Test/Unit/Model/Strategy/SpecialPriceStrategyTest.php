<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Model\Strategy;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Model\Strategy\SpecialPriceStrategy;

class SpecialPriceStrategyTest extends TestCase
{
    private SpecialPriceStrategy $strategy;
    private AbstractItem&MockObject $item;

    protected function setUp(): void
    {
        $this->strategy = new SpecialPriceStrategy();
        $this->item = $this->createMock(AbstractItem::class);
    }

    /**
     * @dataProvider priceScenarioProvider
     */
    public function testShouldExcludeFromDiscount(
        ?float $specialPrice,
        float $finalPrice,
        float $regularPrice,
        bool $expectedExclude,
        string $description
    ): void {
        $product = $this->createProductMock($specialPrice, $finalPrice, $regularPrice);

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertEquals($expectedExclude, $result, $description);
    }

    /**
     * @return array<string, array{float|null, float, float, bool, string}>
     */
    public static function priceScenarioProvider(): array
    {
        return [
            'no_special_price_set' => [
                null,
                19.99,
                19.99,
                false,
                'Products without special price should not be excluded'
            ],
            'zero_special_price' => [
                0.0,
                19.99,
                19.99,
                false,
                'Products with zero special price should not be excluded'
            ],
            'special_price_equals_final_price' => [
                14.99,
                14.99,
                19.99,
                true,
                'Products with active special price (special = final) should be excluded'
            ],
            'special_price_not_winning' => [
                15.99,
                12.99,
                19.99,
                false,
                'Products where special price is NOT the winning price should not be excluded'
            ],
            'special_price_higher_than_regular' => [
                24.99,
                19.99,
                19.99,
                false,
                'Products where special price is higher than regular should not be excluded'
            ],
            'special_price_equals_regular' => [
                19.99,
                19.99,
                19.99,
                true,
                'Products where special price equals final price are excluded (even if same as regular)'
            ],
            'negative_special_price' => [
                -5.0,
                19.99,
                19.99,
                false,
                'Products with negative special price should not be excluded'
            ],
        ];
    }

    public function testActiveSpecialPriceExcludes(): void
    {
        // Scenario: Product has active special price that IS the winning price
        $product = $this->createProductMock(
            specialPrice: 29.99,
            finalPrice: 29.99,
            regularPrice: 39.99
        );

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertTrue($result, 'Product with active special price should be excluded');
    }

    public function testCatalogRuleBeatSpecialPrice(): void
    {
        // Scenario: Catalog rule price (25.99) is lower than special price (29.99)
        // Final price will be catalog rule price, so special price is not "active"
        $product = $this->createProductMock(
            specialPrice: 29.99,
            finalPrice: 25.99, // Catalog rule wins
            regularPrice: 39.99
        );

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertFalse($result, 'Product where catalog rule beats special price should not be excluded by special price strategy');
    }

    public function testNoSpecialPriceDoesNotExclude(): void
    {
        // Scenario: No special price set
        $product = $this->createProductMock(
            specialPrice: null,
            finalPrice: 39.99,
            regularPrice: 39.99
        );

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertFalse($result, 'Product without special price should not be excluded');
    }

    /**
     * Create a mock product with price data
     */
    private function createProductMock(?float $specialPrice, float $finalPrice, float $regularPrice): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getSpecialPrice')->willReturn($specialPrice);
        $product->method('getFinalPrice')->willReturn($finalPrice);
        $product->method('getPrice')->willReturn($regularPrice);

        return $product;
    }
}

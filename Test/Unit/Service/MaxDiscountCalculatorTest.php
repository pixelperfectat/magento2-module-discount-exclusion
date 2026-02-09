<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Service;

use Magento\Catalog\Model\Product;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Service\MaxDiscountCalculator;

class MaxDiscountCalculatorTest extends TestCase
{
    private MaxDiscountCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MaxDiscountCalculator();
    }

    /**
     * @dataProvider calculationProvider
     */
    public function testCalculate(
        float $regularPrice,
        float $finalPrice,
        string $simpleAction,
        float $discountAmount,
        float $qty,
        BypassResultType $expectedType,
        float $expectedAdditionalDiscount,
        string $description,
    ): void {
        $product = $this->createProductMock($regularPrice, $finalPrice);
        $rule = $this->createRuleMock($simpleAction, $discountAmount);

        $result = $this->calculator->calculate($product, $rule, $qty);

        $this->assertSame($expectedType, $result->type, "Type mismatch: $description");
        $this->assertEqualsWithDelta(
            $expectedAdditionalDiscount,
            $result->additionalDiscount,
            0.01,
            "Additional discount mismatch: $description"
        );
        $this->assertEqualsWithDelta(
            $expectedAdditionalDiscount * $qty,
            $result->maxAllowedTotal,
            0.01,
            "Max allowed total mismatch: $description"
        );
        $this->assertEqualsWithDelta($regularPrice, $result->regularPrice, 0.01);
        $this->assertEqualsWithDelta($finalPrice, $result->currentPrice, 0.01);
        $this->assertEqualsWithDelta($qty, $result->qty, 0.01);
    }

    /**
     * @return array<string, array{float, float, string, float, float, BypassResultType, float, string}>
     */
    public static function calculationProvider(): array
    {
        return [
            'by_percent_rule_30_on_product_25_off' => [
                100.0,              // regularPrice
                75.0,               // finalPrice (25% off)
                Rule::BY_PERCENT_ACTION,
                30.0,               // discountAmount (30%)
                1.0,                // qty
                BypassResultType::ADJUSTED,
                5.0,                // additionalDiscount (30 - 25 = 5)
                '30% rule on 25% discounted product → adjusted with €5 additional',
            ],
            'by_percent_rule_20_on_product_25_off' => [
                100.0,
                75.0,
                Rule::BY_PERCENT_ACTION,
                20.0,
                1.0,
                BypassResultType::EXISTING_BETTER,
                0.0,
                '20% rule on 25% discounted product → existing better',
            ],
            'by_percent_rule_25_on_product_25_off' => [
                100.0,
                75.0,
                Rule::BY_PERCENT_ACTION,
                25.0,
                1.0,
                BypassResultType::EXISTING_BETTER,
                0.0,
                '25% rule on 25% discounted product → existing better (equal = no additional)',
            ],
            'by_fixed_rule_10_on_product_discounted_7_50' => [
                100.0,
                92.50,
                Rule::BY_FIXED_ACTION,
                10.0,
                1.0,
                BypassResultType::ADJUSTED,
                2.50,
                '€10 fixed on product discounted €7.50 → adjusted with €2.50 additional',
            ],
            'by_fixed_rule_5_on_product_discounted_7_50' => [
                100.0,
                92.50,
                Rule::BY_FIXED_ACTION,
                5.0,
                1.0,
                BypassResultType::EXISTING_BETTER,
                0.0,
                '€5 fixed on product discounted €7.50 → existing better',
            ],
            'cart_fixed_returns_stacking_fallback' => [
                100.0,
                75.0,
                Rule::CART_FIXED_ACTION,
                20.0,
                1.0,
                BypassResultType::STACKING_FALLBACK,
                0.0,
                'cart_fixed → stacking fallback',
            ],
            'buy_x_get_y_returns_stacking_fallback' => [
                100.0,
                75.0,
                Rule::BUY_X_GET_Y_ACTION,
                1.0,
                2.0,
                BypassResultType::STACKING_FALLBACK,
                0.0,
                'buy_x_get_y → stacking fallback',
            ],
            'zero_regular_price_returns_existing_better' => [
                0.0,
                0.0,
                Rule::BY_PERCENT_ACTION,
                30.0,
                1.0,
                BypassResultType::EXISTING_BETTER,
                0.0,
                'Zero regular price → existing better (avoid division by zero)',
            ],
            'product_not_discounted_full_rule_discount' => [
                100.0,
                100.0,
                Rule::BY_PERCENT_ACTION,
                30.0,
                1.0,
                BypassResultType::ADJUSTED,
                30.0,
                'Product not discounted → adjusted with full rule discount €30',
            ],
            'by_percent_with_qty_3' => [
                100.0,
                75.0,
                Rule::BY_PERCENT_ACTION,
                30.0,
                3.0,
                BypassResultType::ADJUSTED,
                5.0,
                '30% rule on 25% off product, qty 3 → €5 per unit, €15 total',
            ],
        ];
    }

    public function testPercentageFieldsAreCorrect(): void
    {
        $product = $this->createProductMock(100.0, 75.0);
        $rule = $this->createRuleMock(Rule::BY_PERCENT_ACTION, 30.0);

        $result = $this->calculator->calculate($product, $rule, 1.0);

        $this->assertEqualsWithDelta(25.0, $result->existingDiscountPercent, 0.01);
        $this->assertEqualsWithDelta(30.0, $result->ruleDiscountPercent, 0.01);
        $this->assertEqualsWithDelta(25.0, $result->existingDiscountAmount, 0.01);
        $this->assertEqualsWithDelta(30.0, $result->ruleDiscountFromRegular, 0.01);
    }

    public function testByFixedPercentageFieldsAreCorrect(): void
    {
        $product = $this->createProductMock(200.0, 160.0);
        $rule = $this->createRuleMock(Rule::BY_FIXED_ACTION, 50.0);

        $result = $this->calculator->calculate($product, $rule, 2.0);

        $this->assertSame(BypassResultType::ADJUSTED, $result->type);
        $this->assertEqualsWithDelta(10.0, $result->additionalDiscount, 0.01);
        $this->assertEqualsWithDelta(20.0, $result->maxAllowedTotal, 0.01);
        $this->assertEqualsWithDelta(20.0, $result->existingDiscountPercent, 0.01);
        $this->assertEqualsWithDelta(25.0, $result->ruleDiscountPercent, 0.01);
    }

    private function createProductMock(float $regularPrice, float $finalPrice): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getPrice')->willReturn($regularPrice);
        $product->method('getFinalPrice')->willReturn($finalPrice);

        return $product;
    }

    private function createRuleMock(string $simpleAction, float $discountAmount): Rule&MockObject
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->addMethods(['getDiscountAmount'])
            ->onlyMethods(['getSimpleAction'])
            ->getMock();
        $rule->method('getSimpleAction')->willReturn($simpleAction);
        $rule->method('getDiscountAmount')->willReturn($discountAmount);

        return $rule;
    }
}

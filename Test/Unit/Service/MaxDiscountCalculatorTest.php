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
            'by_percent_rule_25_on_cent_rounded_25_off_no_phantom_penny' => [
                29.90,              // regularPrice
                22.43,              // finalPrice: 25% off (22.425) cent-rounded up
                Rule::BY_PERCENT_ACTION,
                25.0,               // discountAmount (25%)
                1.0,                // qty
                BypassResultType::EXISTING_BETTER,
                0.0,                // sub-cent residue is rounding noise, not a real discount
                'Rule % equals a cent-rounded catalog-rule discount → existing better, no phantom penny (#8)',
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

    /**
     * Characterization: the cap is kept at four decimals and is NOT rounded to currency precision.
     *
     * Regular 19.99, catalog rule 10% (final 17.99), coupon 15% with bypass: the rule would give
     * 2.9985 off the regular price, the product already has 2.00 off, so the cap is 0.9985.
     *
     * This is intentional. Magento itself rounds discounts with carried deltas
     * (SalesRule\Model\Utility::deltaRoundingFix), and rounding the cap here on its own would
     * introduce drift between line items and totals. Do not change it without checking a real
     * checkout against a payment provider's line-item totals.
     */
    public function testCapKeepsFourDecimalsAndIsNotRoundedToCents(): void
    {
        $product = $this->createProductMock(19.99, 17.99);
        $rule = $this->createRuleMock(Rule::BY_PERCENT_ACTION, 15.0);

        $single = $this->calculator->calculate($product, $rule, 1.0);
        $triple = $this->calculator->calculate($product, $rule, 3.0);

        $this->assertSame(BypassResultType::ADJUSTED, $single->type);
        $this->assertSame(0.9985, $single->additionalDiscount);
        $this->assertSame(0.9985, $single->maxAllowedTotal);
        $this->assertSame(2.9985, $single->ruleDiscountFromRegular);
        $this->assertSame(2.0, $single->existingDiscountAmount);
        $this->assertSame(0.9985, $triple->additionalDiscount);
        $this->assertSame(2.9955, $triple->maxAllowedTotal, 'The line cap is per-unit cap × qty, rounded once');
    }

    public function testAmountsAreRoundedToFourDecimals(): void
    {
        // 15.5% of 19.99 = 3.09845; Magento stores catalog prices with up to six decimals.
        $product = $this->createProductMock(19.99, 17.45678);
        $rule = $this->createRuleMock(Rule::BY_PERCENT_ACTION, 15.5);

        $result = $this->calculator->calculate($product, $rule, 2.0);

        $this->assertSame(3.0985, $result->ruleDiscountFromRegular);
        $this->assertSame(2.5332, $result->existingDiscountAmount);
        $this->assertSame(0.5652, $result->additionalDiscount);
        $this->assertSame(1.1305, $result->maxAllowedTotal);
    }

    public function testPercentagesAreRoundedToTwoDecimals(): void
    {
        // existing 2.00 / 19.99 = 10.005 %, fixed rule 3.00 / 19.99 = 15.0075 %
        $product = $this->createProductMock(19.99, 17.99);
        $rule = $this->createRuleMock(Rule::BY_FIXED_ACTION, 3.0);

        $result = $this->calculator->calculate($product, $rule, 1.0);

        $this->assertSame(10.01, $result->existingDiscountPercent);
        $this->assertSame(15.01, $result->ruleDiscountPercent);
    }

    /**
     * The #8 fix: a line is only ADJUSTED when the additional discount is at least one cent
     * across the whole line, so per-unit sub-cent amounts count once qty makes them a cent.
     *
     * @dataProvider lineCentThresholdProvider
     */
    public function testAdjustedNeedsAtLeastOneCentAcrossTheLine(
        float $finalPrice,
        float $qty,
        BypassResultType $expected,
    ): void {
        $product = $this->createProductMock(100.0, $finalPrice);
        $rule = $this->createRuleMock(Rule::BY_FIXED_ACTION, 10.0);

        $result = $this->calculator->calculate($product, $rule, $qty);

        $this->assertSame($expected, $result->type);
    }

    /**
     * @return array<string, array{float, float, BypassResultType}>
     */
    public static function lineCentThresholdProvider(): array
    {
        return [
            'half a cent per unit, qty 1' => [90.005, 1.0, BypassResultType::EXISTING_BETTER],
            'half a cent per unit, qty 2 = one cent' => [90.005, 2.0, BypassResultType::ADJUSTED],
            'one cent per unit, qty 1' => [90.01, 1.0, BypassResultType::ADJUSTED],
            'a tenth of a cent per unit, qty 5' => [90.001, 5.0, BypassResultType::EXISTING_BETTER],
        ];
    }

    public function testExistingBetterReportsNoAdditionalDiscountButKeepsTheComparison(): void
    {
        $product = $this->createProductMock(100.0, 75.0);
        $rule = $this->createRuleMock(Rule::BY_PERCENT_ACTION, 20.0);

        $result = $this->calculator->calculate($product, $rule, 2.0);

        $this->assertSame(BypassResultType::EXISTING_BETTER, $result->type);
        $this->assertSame(0.0, $result->additionalDiscount);
        $this->assertSame(0.0, $result->maxAllowedTotal);
        $this->assertSame(25.0, $result->existingDiscountAmount);
        $this->assertSame(20.0, $result->ruleDiscountFromRegular);
        $this->assertSame(25.0, $result->existingDiscountPercent);
        $this->assertSame(20.0, $result->ruleDiscountPercent);
    }

    public function testStackingFallbackCarriesNoComparisonAmounts(): void
    {
        $product = $this->createProductMock(100.0, 75.0);
        $rule = $this->createRuleMock(Rule::CART_FIXED_ACTION, 20.0);

        $result = $this->calculator->calculate($product, $rule, 1.0);

        $this->assertSame(BypassResultType::STACKING_FALLBACK, $result->type);
        $this->assertSame(0.0, $result->existingDiscountAmount);
        $this->assertSame(0.0, $result->ruleDiscountFromRegular);
        $this->assertSame(0.0, $result->existingDiscountPercent);
        $this->assertSame(0.0, $result->ruleDiscountPercent);
        $this->assertSame(0.0, $result->maxAllowedTotal);
    }

    public function testZeroRegularPriceNeverGetsAFixedDiscount(): void
    {
        $product = $this->createProductMock(0.0, 0.0);
        $rule = $this->createRuleMock(Rule::BY_FIXED_ACTION, 5.0);

        $result = $this->calculator->calculate($product, $rule, 1.0);

        $this->assertSame(BypassResultType::EXISTING_BETTER, $result->type);
        $this->assertSame(0.0, $result->maxAllowedTotal);
        $this->assertSame(0.0, $result->existingDiscountAmount);
        $this->assertSame(0.0, $result->ruleDiscountFromRegular);
        $this->assertSame(0.0, $result->existingDiscountPercent);
        $this->assertSame(0.0, $result->ruleDiscountPercent);
    }

    public function testUnknownActionOnFullPriceProductGivesNoDiscount(): void
    {
        $product = $this->createProductMock(100.0, 100.0);
        $rule = $this->createRuleMock('ampromo_items', 1.0);

        $result = $this->calculator->calculate($product, $rule, 1.0);

        $this->assertSame(BypassResultType::EXISTING_BETTER, $result->type);
        $this->assertSame(0.0, $result->ruleDiscountFromRegular);
        $this->assertSame(0.0, $result->maxAllowedTotal);
    }

    /**
     * Magento returns prices and rule amounts from the database as numeric strings.
     */
    public function testAcceptsNumericStringsAsMagentoReturnsThem(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getPrice')->willReturn('100.000000');
        $product->method('getFinalPrice')->willReturn('92.500000');

        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->addMethods(['getDiscountAmount'])
            ->onlyMethods(['getSimpleAction'])
            ->getMock();
        $rule->method('getSimpleAction')->willReturn(Rule::BY_FIXED_ACTION);
        $rule->method('getDiscountAmount')->willReturn('10.0000');

        $result = $this->calculator->calculate($product, $rule, 1.0);

        $this->assertSame(BypassResultType::ADJUSTED, $result->type);
        $this->assertSame(100.0, $result->regularPrice);
        $this->assertSame(92.5, $result->currentPrice);
        $this->assertSame(10.0, $result->ruleDiscountFromRegular);
        $this->assertSame(2.5, $result->maxAllowedTotal);
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

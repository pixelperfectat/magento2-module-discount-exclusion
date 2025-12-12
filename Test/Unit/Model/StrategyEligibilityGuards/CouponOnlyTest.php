<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Model\StrategyEligibilityGuards;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\CouponOnly;

class CouponOnlyTest extends TestCase
{
    private CouponOnly $guard;
    private Product&MockObject $product;
    private AbstractItem&MockObject $item;

    protected function setUp(): void
    {
        $this->guard = new CouponOnly();
        $this->product = $this->createMock(Product::class);
        $this->item = $this->createMock(AbstractItem::class);
    }

    /**
     * @dataProvider couponTypeProvider
     */
    public function testCanProcess(int $couponType, bool $expectedResult, string $description): void
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponType'])
            ->getMock();
        $rule->method('getCouponType')->willReturn($couponType);

        $result = $this->guard->canProcess($this->product, $this->item, $rule);

        $this->assertEquals($expectedResult, $result, $description);
    }

    /**
     * @return array<string, array{int, bool, string}>
     */
    public static function couponTypeProvider(): array
    {
        return [
            'no_coupon_rule_should_skip' => [
                Rule::COUPON_TYPE_NO_COUPON, // 1
                false,
                'Automatic rules (no coupon) should be skipped (return false)'
            ],
            'specific_coupon_should_process' => [
                Rule::COUPON_TYPE_SPECIFIC, // 2
                true,
                'Rules with specific coupon should be processed (return true)'
            ],
            'auto_generated_coupon_should_process' => [
                Rule::COUPON_TYPE_AUTO, // 3
                true,
                'Rules with auto-generated coupons should be processed (return true)'
            ],
        ];
    }

    public function testAutomaticRulesAreNotBlocked(): void
    {
        // Automatic rules like free shipping should NOT have exclusion logic applied
        $freeShippingRule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponType'])
            ->getMock();
        $freeShippingRule->method('getCouponType')->willReturn(Rule::COUPON_TYPE_NO_COUPON);

        $result = $this->guard->canProcess($this->product, $this->item, $freeShippingRule);

        $this->assertFalse($result, 'Automatic rules should return false to skip exclusion logic');
    }

    public function testCouponBasedRulesAreProcessed(): void
    {
        // Coupon-based rules should have exclusion logic applied
        $couponRule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponType'])
            ->getMock();
        $couponRule->method('getCouponType')->willReturn(Rule::COUPON_TYPE_SPECIFIC);

        $result = $this->guard->canProcess($this->product, $this->item, $couponRule);

        $this->assertTrue($result, 'Coupon-based rules should return true to apply exclusion logic');
    }
}

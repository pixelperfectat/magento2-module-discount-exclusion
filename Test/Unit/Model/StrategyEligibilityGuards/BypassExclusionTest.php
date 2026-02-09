<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Model\StrategyEligibilityGuards;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\BypassExclusion;

class BypassExclusionTest extends TestCase
{
    private BypassExclusion $guard;
    private Product&MockObject $product;
    private AbstractItem&MockObject $item;

    protected function setUp(): void
    {
        $this->guard = new BypassExclusion();
        $this->product = $this->createMock(Product::class);
        $this->item = $this->createMock(AbstractItem::class);
    }

    /**
     * @dataProvider bypassValueProvider
     */
    public function testCanProcess(mixed $bypassValue, bool $expectedResult, string $description): void
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $rule->method('getData')
            ->with('bypass_discount_exclusion')
            ->willReturn($bypassValue);

        $result = $this->guard->canProcess($this->product, $this->item, $rule);

        $this->assertEquals($expectedResult, $result, $description);
    }

    /**
     * @return array<string, array{mixed, bool, string}>
     */
    public static function bypassValueProvider(): array
    {
        return [
            'bypass_enabled_int' => [
                1,
                false,
                'When bypass is enabled (int 1), exclusion logic should be skipped (return false)'
            ],
            'bypass_enabled_string' => [
                '1',
                false,
                'When bypass is enabled (string "1"), exclusion logic should be skipped (return false)'
            ],
            'bypass_disabled_int' => [
                0,
                true,
                'When bypass is disabled (int 0), exclusion logic should apply (return true)'
            ],
            'bypass_disabled_string' => [
                '0',
                true,
                'When bypass is disabled (string "0"), exclusion logic should apply (return true)'
            ],
            'bypass_null' => [
                null,
                true,
                'When bypass is null (column not set), exclusion logic should apply (return true)'
            ],
        ];
    }

    public function testBypassEnabledSkipsExclusionLogic(): void
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $rule->method('getData')
            ->with('bypass_discount_exclusion')
            ->willReturn(1);

        $result = $this->guard->canProcess($this->product, $this->item, $rule);

        $this->assertFalse($result, 'Rules with bypass enabled should skip exclusion logic');
    }

    public function testBypassDisabledAllowsExclusionLogic(): void
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $rule->method('getData')
            ->with('bypass_discount_exclusion')
            ->willReturn(0);

        $result = $this->guard->canProcess($this->product, $this->item, $rule);

        $this->assertTrue($result, 'Rules without bypass should have exclusion logic applied');
    }
}

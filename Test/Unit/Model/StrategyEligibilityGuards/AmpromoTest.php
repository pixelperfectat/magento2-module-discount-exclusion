<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Model\StrategyEligibilityGuards;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\Ampromo;

class AmpromoTest extends TestCase
{
    private Ampromo $guard;
    private Product&MockObject $product;
    private AbstractItem&MockObject $item;

    protected function setUp(): void
    {
        $this->guard = new Ampromo();
        $this->product = $this->createMock(Product::class);
        $this->item = $this->createMock(AbstractItem::class);
    }

    /**
     * @dataProvider simpleActionProvider
     */
    public function testCanProcess(?string $simpleAction, bool $expectedResult, string $description): void
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getSimpleAction')->willReturn($simpleAction);

        $result = $this->guard->canProcess($this->product, $this->item, $rule);

        $this->assertEquals($expectedResult, $result, $description);
    }

    /**
     * @return array<string, array{string|null, bool, string}>
     */
    public static function simpleActionProvider(): array
    {
        return [
            'ampromo_product_should_skip' => [
                'ampromo_product',
                false,
                'Ampromo product rules should skip exclusion logic'
            ],
            'ampromo_items_should_skip' => [
                'ampromo_items',
                false,
                'Ampromo items rules should skip exclusion logic'
            ],
            'ampromo_cart_should_skip' => [
                'ampromo_cart',
                false,
                'Ampromo cart rules should skip exclusion logic'
            ],
            'by_percent_should_process' => [
                'by_percent',
                true,
                'Percentage discount rules should process exclusion logic'
            ],
            'by_fixed_should_process' => [
                'by_fixed',
                true,
                'Fixed amount discount rules should process exclusion logic'
            ],
            'cart_fixed_should_process' => [
                'cart_fixed',
                true,
                'Cart fixed discount rules should process exclusion logic'
            ],
            'null_action_should_process' => [
                null,
                true,
                'Rules with null simple action should process exclusion logic'
            ],
            'empty_action_should_process' => [
                '',
                true,
                'Rules with empty simple action should process exclusion logic'
            ],
        ];
    }

    public function testAmpromoRulesAllowFreeGifts(): void
    {
        // Free gift rules should NOT have exclusion logic applied
        // This ensures free gifts can be added even to discounted products
        $freeGiftRule = $this->createMock(Rule::class);
        $freeGiftRule->method('getSimpleAction')->willReturn('ampromo_product');

        $result = $this->guard->canProcess($this->product, $this->item, $freeGiftRule);

        $this->assertFalse($result, 'Ampromo rules should return false to allow free gifts');
    }

    public function testRegularDiscountRulesAreProcessed(): void
    {
        // Regular percentage discount rules should have exclusion logic applied
        $percentRule = $this->createMock(Rule::class);
        $percentRule->method('getSimpleAction')->willReturn('by_percent');

        $result = $this->guard->canProcess($this->product, $this->item, $percentRule);

        $this->assertTrue($result, 'Regular discount rules should be processed for exclusion');
    }
}

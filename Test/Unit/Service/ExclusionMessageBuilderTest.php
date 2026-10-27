<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Service;

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Service\ExclusionMessageBuilder;

class ExclusionMessageBuilderTest extends TestCase
{
    private ExclusionMessageBuilder $builder;
    private ExclusionResultCollectorInterface&MockObject $resultCollector;
    private ManagerInterface&MockObject $messageManager;
    private PriceCurrencyInterface&MockObject $priceCurrency;
    private ConfigInterface&MockObject $config;

    protected function setUp(): void
    {
        $this->resultCollector = $this->createMock(ExclusionResultCollectorInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isMessagesEnabled')->willReturn(true);

        $this->builder = new ExclusionMessageBuilder(
            $this->resultCollector,
            $this->messageManager,
            $this->priceCurrency,
            $this->config,
        );
    }

    public function testBuildReturnsEmptyArrayWhenCollectorIsEmpty(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(false);

        $messages = $this->builder->buildMessagesForCoupon('SAVE10');

        $this->assertEmpty($messages);
    }

    public function testBuildExclusionMessageForSingleProduct(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->willReturn(false);
        $this->resultCollector->method('getExcludedItems')->willReturn([
            '100' => ['name' => 'Widget A', 'reason' => 'Already discounted'],
        ]);

        $messages = $this->builder->buildMessagesForCoupon('SAVE10');

        $this->assertCount(1, $messages);
        $this->assertEquals('warning', $messages[0]['type']);
        $this->assertStringContainsString('Widget A', $messages[0]['text']);
    }

    public function testBuildExclusionMessageForMultipleProducts(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->willReturn(false);
        $this->resultCollector->method('getExcludedItems')->willReturn([
            '100' => ['name' => 'Widget A', 'reason' => 'Already discounted'],
            '200' => ['name' => 'Widget B', 'reason' => 'Already discounted'],
        ]);

        $messages = $this->builder->buildMessagesForCoupon('SAVE10');

        $this->assertCount(1, $messages);
        $this->assertEquals('warning', $messages[0]['type']);
        $this->assertStringContainsString('following products', $messages[0]['text']);
    }

    public function testBuildBypassAdjustedPercentMessage(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getBypassedItems')->willReturn([
            '100' => [
                'name' => 'Widget A',
                'type' => BypassResultType::ADJUSTED,
                'messageParams' => [
                    'simpleAction' => 'by_percent',
                    'ruleDiscountPercent' => 30.0,
                    'existingDiscountPercent' => 25.0,
                    'additionalDiscountPercent' => 5.0,
                ],
            ],
        ]);

        $messages = $this->builder->buildMessagesForCoupon('SAVE30');

        $this->assertCount(1, $messages);
        $this->assertEquals('notice', $messages[0]['type']);
        $this->assertStringContainsString('additional', $messages[0]['text']);
    }

    public function testBuildBypassAdjustedFixedMessage(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getBypassedItems')->willReturn([
            '100' => [
                'name' => 'Widget A',
                'type' => BypassResultType::ADJUSTED,
                'messageParams' => [
                    'simpleAction' => 'by_fixed',
                    'ruleDiscountAmount' => 10.0,
                    'existingDiscountAmount' => 7.50,
                    'additionalDiscountAmount' => 2.50,
                ],
            ],
        ]);

        $this->priceCurrency->method('format')
            ->willReturnCallback(fn(float $amount) => '€' . number_format($amount, 2));

        $messages = $this->builder->buildMessagesForCoupon('SAVE10');

        $this->assertCount(1, $messages);
        $this->assertEquals('notice', $messages[0]['type']);
        $this->assertStringContainsString('additional', $messages[0]['text']);
    }

    public function testBuildBypassExistingBetterPercentMessage(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getBypassedItems')->willReturn([
            '100' => [
                'name' => 'Widget A',
                'type' => BypassResultType::EXISTING_BETTER,
                'messageParams' => [
                    'simpleAction' => 'by_percent',
                    'ruleDiscountPercent' => 20.0,
                    'existingDiscountPercent' => 25.0,
                ],
            ],
        ]);

        $messages = $this->builder->buildMessagesForCoupon('SAVE20');

        $this->assertCount(1, $messages);
        $this->assertEquals('warning', $messages[0]['type']);
        $this->assertStringContainsString('exceeds', $messages[0]['text']);
    }

    public function testBuildBypassExistingBetterFixedMessage(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getBypassedItems')->willReturn([
            '100' => [
                'name' => 'Widget A',
                'type' => BypassResultType::EXISTING_BETTER,
                'messageParams' => [
                    'simpleAction' => 'by_fixed',
                    'ruleDiscountAmount' => 5.0,
                    'existingDiscountAmount' => 7.50,
                ],
            ],
        ]);

        $this->priceCurrency->method('format')
            ->willReturnCallback(fn(float $amount) => '€' . number_format($amount, 2));

        $messages = $this->builder->buildMessagesForCoupon('SAVE5');

        $this->assertCount(1, $messages);
        $this->assertEquals('warning', $messages[0]['type']);
        $this->assertStringContainsString('exceeds', $messages[0]['text']);
    }

    public function testBuildCombinedExclusionAndBypassMessages(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getExcludedItems')->willReturn([
            '100' => ['name' => 'Excluded Product', 'reason' => 'Already discounted'],
        ]);
        $this->resultCollector->method('getBypassedItems')->willReturn([
            '200' => [
                'name' => 'Bypassed Product',
                'type' => BypassResultType::ADJUSTED,
                'messageParams' => [
                    'simpleAction' => 'by_percent',
                    'ruleDiscountPercent' => 30.0,
                    'existingDiscountPercent' => 25.0,
                    'additionalDiscountPercent' => 5.0,
                ],
            ],
        ]);

        $messages = $this->builder->buildMessagesForCoupon('SAVE30');

        $this->assertCount(2, $messages);
        $this->assertEquals('warning', $messages[0]['type']);
        $this->assertEquals('notice', $messages[1]['type']);
    }

    public function testBuildStackingFallbackProducesNoMessage(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getBypassedItems')->willReturn([
            '100' => [
                'name' => 'Widget A',
                'type' => BypassResultType::STACKING_FALLBACK,
                'messageParams' => ['simpleAction' => 'cart_fixed'],
            ],
        ]);

        $messages = $this->builder->buildMessagesForCoupon('SAVE10');

        $this->assertEmpty($messages);
    }

    public function testAddMessagesForCouponDelegatesToBuild(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getExcludedItems')->willReturn([
            '100' => ['name' => 'Excluded Product', 'reason' => 'Already discounted'],
        ]);
        $this->resultCollector->method('getBypassedItems')->willReturn([
            '200' => [
                'name' => 'Bypassed Product',
                'type' => BypassResultType::ADJUSTED,
                'messageParams' => [
                    'simpleAction' => 'by_percent',
                    'ruleDiscountPercent' => 30.0,
                    'existingDiscountPercent' => 25.0,
                    'additionalDiscountPercent' => 5.0,
                ],
            ],
        ]);

        // Exclusion → warning, bypass adjusted → notice
        $this->messageManager->expects($this->once())->method('addWarningMessage');
        $this->messageManager->expects($this->once())->method('addNoticeMessage');

        $this->builder->addMessagesForCoupon('SAVE30');
    }

    public function testAddMessagesRoutesEachMessageToItsSeverity(): void
    {
        $this->givenBypassedItems([
            '1' => $this->bypassed('Widget A', BypassResultType::EXISTING_BETTER, [
                'simpleAction' => 'by_percent',
                'ruleDiscountPercent' => 20.0,
                'existingDiscountPercent' => 25.0,
            ]),
            '2' => $this->bypassed('Widget B', BypassResultType::ADJUSTED, [
                'simpleAction' => 'by_percent',
                'ruleDiscountPercent' => 30.0,
                'existingDiscountPercent' => 25.0,
                'additionalDiscountPercent' => 5.0,
            ]),
        ]);

        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with($this->stringContains('"Widget A"'));
        $this->messageManager->expects($this->once())
            ->method('addNoticeMessage')
            ->with($this->stringContains('"Widget B"'));

        $this->builder->addMessagesForCoupon('SAVE');
    }

    public function testNothingIsBuiltOrAddedWhenCustomerMessagesAreDisabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isMessagesEnabled')->willReturn(false);
        $builder = new ExclusionMessageBuilder(
            $this->resultCollector,
            $this->messageManager,
            $this->priceCurrency,
            $config,
        );

        $this->resultCollector->expects($this->never())->method('hasExcludedItems');
        $this->resultCollector->expects($this->never())->method('hasBypassedItems');
        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->assertSame([], $builder->buildMessagesForCoupon('SAVE10'));
        $builder->addMessagesForCoupon('SAVE10');
    }

    public function testExactExclusionTexts(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->willReturn(false);
        $this->resultCollector->method('getExcludedItems')->willReturnOnConsecutiveCalls(
            ['1' => ['name' => 'Widget A', 'reason' => 'r']],
            ['1' => ['name' => 'Widget A', 'reason' => 'r'], '2' => ['name' => 'Widget B', 'reason' => 'r']],
        );

        $this->assertSame(
            [['type' => 'warning', 'text' => 'Coupon "SAVE10" was not applied to "Widget A" because it is already discounted.']],
            $this->builder->buildMessagesForCoupon('SAVE10')
        );
        $this->assertSame(
            [[
                'type' => 'warning',
                'text' => 'Coupon "SAVE10" was not applied to the following products because they are already '
                    . 'discounted: Widget A, Widget B',
            ]],
            $this->builder->buildMessagesForCoupon('SAVE10')
        );
    }

    /**
     * Percentages are shown as whole numbers. The values are deliberately not multiples of ten.
     */
    public function testExactPercentBypassTexts(): void
    {
        $this->givenBypassedItems([
            '1' => $this->bypassed('Widget A', BypassResultType::ADJUSTED, [
                'simpleAction' => 'by_percent',
                'ruleDiscountPercent' => 37.0,
                'existingDiscountPercent' => 23.0,
                'additionalDiscountPercent' => 14.0,
            ]),
            '2' => $this->bypassed('Widget B', BypassResultType::EXISTING_BETTER, [
                'simpleAction' => 'by_percent',
                'ruleDiscountPercent' => 17.0,
                'existingDiscountPercent' => 23.0,
            ]),
        ]);

        $this->assertSame(
            [
                [
                    'type' => 'notice',
                    'text' => 'Coupon "SAVE" applied an additional 14% discount to "Widget A", adjusted from 37% '
                        . 'because it is already 23% discounted.',
                ],
                [
                    'type' => 'warning',
                    'text' => 'Coupon "SAVE" was not applied to "Widget B" because the existing 23% discount '
                        . 'already exceeds the coupon\'s 17% discount.',
                ],
            ],
            $this->builder->buildMessagesForCoupon('SAVE')
        );
    }

    /**
     * Amounts go through PriceCurrency without the HTML price container, because flash
     * messages are escaped and the markup would show up as text.
     */
    public function testExactFixedBypassTextsUsePlainPriceFormatting(): void
    {
        $this->priceCurrency->method('format')->willReturnCallback(
            fn(float $amount, bool $includeContainer) => ($includeContainer ? '<span class="price">' : '')
                . '€' . number_format($amount, 2)
                . ($includeContainer ? '</span>' : '')
        );
        $this->givenBypassedItems([
            '1' => $this->bypassed('Widget A', BypassResultType::ADJUSTED, [
                'simpleAction' => 'by_fixed',
                'ruleDiscountAmount' => 10.0,
                'existingDiscountAmount' => 7.5,
                'additionalDiscountAmount' => 2.5,
            ]),
            '2' => $this->bypassed('Widget B', BypassResultType::EXISTING_BETTER, [
                'simpleAction' => 'by_fixed',
                'ruleDiscountAmount' => 5.0,
                'existingDiscountAmount' => 7.5,
            ]),
        ]);

        $this->assertSame(
            [
                [
                    'type' => 'notice',
                    'text' => 'Coupon "SAVE" applied an additional €2.50 discount to "Widget A", adjusted from €10.00 '
                        . 'because it is already discounted by €7.50.',
                ],
                [
                    'type' => 'warning',
                    'text' => 'Coupon "SAVE" was not applied to "Widget B" because the existing €7.50 discount '
                        . 'already exceeds the coupon\'s €5.00 discount.',
                ],
            ],
            $this->builder->buildMessagesForCoupon('SAVE')
        );
    }

    /**
     * Missing message parameters render as zero instead of failing.
     */
    public function testMissingParametersRenderAsZero(): void
    {
        $this->priceCurrency->method('format')->willReturnCallback(
            fn(float $amount) => '€' . number_format($amount, 2)
        );
        $this->givenBypassedItems([
            '1' => $this->bypassed('Widget A', BypassResultType::ADJUSTED, ['simpleAction' => 'by_percent']),
            '2' => $this->bypassed('Widget B', BypassResultType::ADJUSTED, ['simpleAction' => 'by_fixed']),
            '3' => $this->bypassed('Widget C', BypassResultType::EXISTING_BETTER, ['simpleAction' => 'by_percent']),
            '4' => $this->bypassed('Widget D', BypassResultType::EXISTING_BETTER, ['simpleAction' => 'by_fixed']),
        ]);

        $texts = array_column($this->builder->buildMessagesForCoupon('SAVE'), 'text');

        $this->assertSame(
            [
                'Coupon "SAVE" applied an additional 0% discount to "Widget A", adjusted from 0% because it is '
                    . 'already 0% discounted.',
                'Coupon "SAVE" applied an additional €0.00 discount to "Widget B", adjusted from €0.00 because it '
                    . 'is already discounted by €0.00.',
                'Coupon "SAVE" was not applied to "Widget C" because the existing 0% discount already exceeds the '
                    . 'coupon\'s 0% discount.',
                'Coupon "SAVE" was not applied to "Widget D" because the existing €0.00 discount already exceeds the '
                    . 'coupon\'s €0.00 discount.',
            ],
            $texts
        );
    }

    /**
     * Items without a message (stacking fallback, or an action the module has no text for) are
     * skipped, and the items after them still get their message.
     */
    public function testItemsWithoutAMessageAreSkippedNotFatal(): void
    {
        $this->priceCurrency->method('format')->willReturn('€1.00');
        $this->givenBypassedItems([
            '1' => $this->bypassed('Fallback', BypassResultType::STACKING_FALLBACK, ['simpleAction' => 'by_fixed']),
            '2' => $this->bypassed('Fallback %', BypassResultType::STACKING_FALLBACK, ['simpleAction' => 'by_percent']),
            '3' => $this->bypassed('Cart Fixed', BypassResultType::EXISTING_BETTER, ['simpleAction' => 'cart_fixed']),
            '4' => $this->bypassed('No Action', BypassResultType::ADJUSTED, []),
            '5' => $this->bypassed('Widget E', BypassResultType::EXISTING_BETTER, [
                'simpleAction' => 'by_percent',
                'ruleDiscountPercent' => 10.0,
                'existingDiscountPercent' => 20.0,
            ]),
        ]);

        $messages = $this->builder->buildMessagesForCoupon('SAVE');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('"Widget E"', $messages[0]['text']);
    }

    /**
     * @param array<int|string, array{name: string, type: BypassResultType, messageParams: array<string, float|string>}> $items
     */
    private function givenBypassedItems(array $items): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getBypassedItems')->willReturn($items);
    }

    /**
     * @param array<string, float|string> $params
     * @return array{name: string, type: BypassResultType, messageParams: array<string, float|string>}
     */
    private function bypassed(string $name, BypassResultType $type, array $params): array
    {
        return ['name' => $name, 'type' => $type, 'messageParams' => $params];
    }
}

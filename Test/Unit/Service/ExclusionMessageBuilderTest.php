<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Service;

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Service\ExclusionMessageBuilder;

class ExclusionMessageBuilderTest extends TestCase
{
    private ExclusionMessageBuilder $builder;
    private ExclusionResultCollectorInterface&MockObject $resultCollector;
    private ManagerInterface&MockObject $messageManager;
    private PriceCurrencyInterface&MockObject $priceCurrency;

    protected function setUp(): void
    {
        $this->resultCollector = $this->createMock(ExclusionResultCollectorInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);

        $this->builder = new ExclusionMessageBuilder(
            $this->resultCollector,
            $this->messageManager,
            $this->priceCurrency,
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
}

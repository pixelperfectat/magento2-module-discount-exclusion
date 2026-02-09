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

    public function testNoMessagesWhenCollectorIsEmpty(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(false);

        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->builder->addMessagesForCoupon('SAVE10');
    }

    public function testExclusionMessageForSingleProduct(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->willReturn(false);
        $this->resultCollector->method('getExcludedItems')->willReturn([
            '100' => ['name' => 'Widget A', 'reason' => 'Already discounted'],
        ]);

        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with($this->stringContains('Widget A'));

        $this->builder->addMessagesForCoupon('SAVE10');
    }

    public function testExclusionMessageForMultipleProducts(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->willReturn(false);
        $this->resultCollector->method('getExcludedItems')->willReturn([
            '100' => ['name' => 'Widget A', 'reason' => 'Already discounted'],
            '200' => ['name' => 'Widget B', 'reason' => 'Already discounted'],
        ]);

        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with($this->stringContains('following products'));

        $this->builder->addMessagesForCoupon('SAVE10');
    }

    public function testBypassAdjustedPercentMessage(): void
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

        $this->messageManager->expects($this->once())
            ->method('addNoticeMessage')
            ->with($this->stringContains('additional'));

        $this->builder->addMessagesForCoupon('SAVE30');
    }

    public function testBypassAdjustedFixedMessage(): void
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

        $this->messageManager->expects($this->once())
            ->method('addNoticeMessage')
            ->with($this->stringContains('additional'));

        $this->builder->addMessagesForCoupon('SAVE10');
    }

    public function testBypassExistingBetterPercentMessage(): void
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

        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with($this->stringContains('exceeds'));

        $this->builder->addMessagesForCoupon('SAVE20');
    }

    public function testBypassExistingBetterFixedMessage(): void
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

        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with($this->stringContains('exceeds'));

        $this->builder->addMessagesForCoupon('SAVE5');
    }

    public function testCombinedExclusionAndBypassMessages(): void
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

    public function testStackingFallbackProducesNoMessage(): void
    {
        $this->resultCollector->method('hasExcludedItems')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->willReturn(true);
        $this->resultCollector->method('getBypassedItems')->willReturn([
            '100' => [
                'name' => 'Widget A',
                'type' => BypassResultType::STACKING_FALLBACK,
                'messageParams' => [
                    'simpleAction' => 'cart_fixed',
                ],
            ],
        ]);

        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->builder->addMessagesForCoupon('SAVE10');
    }
}

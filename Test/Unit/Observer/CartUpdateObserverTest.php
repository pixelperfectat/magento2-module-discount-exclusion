<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\ExclusionMessageBuilderInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Observer\CartUpdateObserver;
use Psr\Log\LoggerInterface;

class CartUpdateObserverTest extends TestCase
{
    private CartUpdateObserver $observer;
    private ExclusionResultCollectorInterface&MockObject $resultCollector;
    private ExclusionMessageBuilderInterface&MockObject $messageBuilder;
    private CheckoutSession&MockObject $checkoutSession;
    private Quote&MockObject $quote;

    protected function setUp(): void
    {
        $this->resultCollector = $this->createMock(ExclusionResultCollectorInterface::class);
        $this->messageBuilder = $this->createMock(ExclusionMessageBuilderInterface::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponCode'])
            ->getMock();
        $this->checkoutSession->method('getQuote')->willReturn($this->quote);

        $this->observer = new CartUpdateObserver(
            $this->resultCollector,
            $this->messageBuilder,
            $this->checkoutSession,
            $logger,
        );
    }

    public function testNoCouponClearsCollectorAndSkips(): void
    {
        $this->quote->method('getCouponCode')->willReturn(null);

        $this->resultCollector->expects($this->once())->method('clear');
        $this->messageBuilder->expects($this->never())->method('addMessagesForCoupon');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testEmptyCouponClearsCollectorAndSkips(): void
    {
        $this->quote->method('getCouponCode')->willReturn('');

        $this->resultCollector->expects($this->once())->method('clear');
        $this->messageBuilder->expects($this->never())->method('addMessagesForCoupon');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testCouponWithNoCollectorDataClearsAndSkips(): void
    {
        $this->quote->method('getCouponCode')->willReturn('SAVE10');

        $this->resultCollector->method('hasExcludedItems')->with('SAVE10')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->with('SAVE10')->willReturn(false);

        $this->resultCollector->expects($this->once())->method('clear');
        $this->messageBuilder->expects($this->never())->method('addMessagesForCoupon');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testCouponWithExcludedItemsAddsMessages(): void
    {
        $this->quote->method('getCouponCode')->willReturn('SAVE10');

        $this->resultCollector->method('hasExcludedItems')->with('SAVE10')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->with('SAVE10')->willReturn(false);

        $this->messageBuilder->expects($this->once())
            ->method('addMessagesForCoupon')
            ->with('SAVE10');
        $this->resultCollector->expects($this->once())->method('clear');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testCouponWithBypassedItemsAddsMessages(): void
    {
        $this->quote->method('getCouponCode')->willReturn('SAVE30');

        $this->resultCollector->method('hasExcludedItems')->with('SAVE30')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->with('SAVE30')->willReturn(true);

        $this->messageBuilder->expects($this->once())
            ->method('addMessagesForCoupon')
            ->with('SAVE30');
        $this->resultCollector->expects($this->once())->method('clear');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testCouponWithBothExcludedAndBypassedAddsMessages(): void
    {
        $this->quote->method('getCouponCode')->willReturn('SAVE30');

        $this->resultCollector->method('hasExcludedItems')->with('SAVE30')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->with('SAVE30')->willReturn(true);

        $this->messageBuilder->expects($this->once())
            ->method('addMessagesForCoupon')
            ->with('SAVE30');
        $this->resultCollector->expects($this->once())->method('clear');

        $this->observer->execute($this->createMock(Observer::class));
    }
}

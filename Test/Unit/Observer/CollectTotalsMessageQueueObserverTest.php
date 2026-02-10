<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\ExclusionMessageBuilderInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;
use PixelPerfect\DiscountExclusion\Observer\CollectTotalsMessageQueueObserver;
use Psr\Log\LoggerInterface;

class CollectTotalsMessageQueueObserverTest extends TestCase
{
    private CollectTotalsMessageQueueObserver $observer;
    private ExclusionResultCollectorInterface&MockObject $resultCollector;
    private ExclusionMessageBuilderInterface&MockObject $messageBuilder;
    private CheckoutSession&MockObject $checkoutSession;

    protected function setUp(): void
    {
        $this->resultCollector = $this->createMock(ExclusionResultCollectorInterface::class);
        $this->messageBuilder = $this->createMock(ExclusionMessageBuilderInterface::class);
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->addMethods(['setData'])
            ->getMock();
        $logger = $this->createMock(LoggerInterface::class);

        $this->observer = new CollectTotalsMessageQueueObserver(
            $this->resultCollector,
            $this->messageBuilder,
            $this->checkoutSession,
            $logger,
        );
    }

    public function testNullQuoteClearsCollector(): void
    {
        $this->resultCollector->expects($this->once())->method('clear');
        $this->messageBuilder->expects($this->never())->method('buildMessagesForCoupon');
        $this->checkoutSession->expects($this->never())->method('setData');

        $this->observer->execute($this->createObserverWithQuote(null));
    }

    public function testNullCouponClearsCollector(): void
    {
        $quote = $this->createQuoteWithCoupon(null);

        $this->resultCollector->expects($this->once())->method('clear');
        $this->messageBuilder->expects($this->never())->method('buildMessagesForCoupon');
        $this->checkoutSession->expects($this->never())->method('setData');

        $this->observer->execute($this->createObserverWithQuote($quote));
    }

    public function testEmptyCouponClearsCollector(): void
    {
        $quote = $this->createQuoteWithCoupon('');

        $this->resultCollector->expects($this->once())->method('clear');
        $this->messageBuilder->expects($this->never())->method('buildMessagesForCoupon');

        $this->observer->execute($this->createObserverWithQuote($quote));
    }

    public function testCouponWithNoCollectorDataClearsAndSkips(): void
    {
        $quote = $this->createQuoteWithCoupon('SAVE10');

        $this->resultCollector->method('hasExcludedItems')->with('SAVE10')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->with('SAVE10')->willReturn(false);

        $this->resultCollector->expects($this->once())->method('clear');
        $this->messageBuilder->expects($this->never())->method('buildMessagesForCoupon');

        $this->observer->execute($this->createObserverWithQuote($quote));
    }

    public function testCouponWithExcludedItemsQueuesMessages(): void
    {
        $quote = $this->createQuoteWithCoupon('SAVE10');

        $this->resultCollector->method('hasExcludedItems')->with('SAVE10')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->with('SAVE10')->willReturn(false);

        $expectedMessages = [['type' => 'warning', 'text' => 'Test warning']];
        $this->messageBuilder->expects($this->once())
            ->method('buildMessagesForCoupon')
            ->with('SAVE10')
            ->willReturn($expectedMessages);

        $this->checkoutSession->expects($this->once())
            ->method('setData')
            ->with(SessionKeys::QUEUED_DISCOUNT_MESSAGES, $expectedMessages);

        $this->resultCollector->expects($this->once())->method('clear');

        $this->observer->execute($this->createObserverWithQuote($quote));
    }

    public function testCouponWithBypassedItemsQueuesMessages(): void
    {
        $quote = $this->createQuoteWithCoupon('SAVE30');

        $this->resultCollector->method('hasExcludedItems')->with('SAVE30')->willReturn(false);
        $this->resultCollector->method('hasBypassedItems')->with('SAVE30')->willReturn(true);

        $expectedMessages = [['type' => 'notice', 'text' => 'Test notice']];
        $this->messageBuilder->expects($this->once())
            ->method('buildMessagesForCoupon')
            ->with('SAVE30')
            ->willReturn($expectedMessages);

        $this->checkoutSession->expects($this->once())
            ->method('setData')
            ->with(SessionKeys::QUEUED_DISCOUNT_MESSAGES, $expectedMessages);

        $this->observer->execute($this->createObserverWithQuote($quote));
    }

    public function testEmptyBuildResultDoesNotQueueToSession(): void
    {
        $quote = $this->createQuoteWithCoupon('SAVE10');

        $this->resultCollector->method('hasExcludedItems')->willReturn(true);
        $this->resultCollector->method('hasBypassedItems')->willReturn(false);

        $this->messageBuilder->method('buildMessagesForCoupon')->willReturn([]);

        $this->checkoutSession->expects($this->never())->method('setData');
        $this->resultCollector->expects($this->once())->method('clear');

        $this->observer->execute($this->createObserverWithQuote($quote));
    }

    /**
     * Create a Quote mock with a coupon code
     */
    private function createQuoteWithCoupon(?string $couponCode): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponCode'])
            ->getMock();
        $quote->method('getCouponCode')->willReturn($couponCode);

        return $quote;
    }

    /**
     * Create an Observer mock with a quote on the event
     */
    private function createObserverWithQuote(?Quote $quote): Observer
    {
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getQuote'])
            ->getMock();
        $event->method('getQuote')->willReturn($quote);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}

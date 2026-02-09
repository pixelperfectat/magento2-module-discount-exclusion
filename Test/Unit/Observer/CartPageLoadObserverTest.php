<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;
use PixelPerfect\DiscountExclusion\Observer\CartPageLoadObserver;

class CartPageLoadObserverTest extends TestCase
{
    private CartPageLoadObserver $observer;
    private CheckoutSession&MockObject $checkoutSession;
    private ManagerInterface&MockObject $messageManager;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['unsetData'])
            ->getMock();
        $this->messageManager = $this->createMock(ManagerInterface::class);

        $this->observer = new CartPageLoadObserver(
            $this->checkoutSession,
            $this->messageManager,
        );
    }

    public function testNoQueuedMessagesAddsNothing(): void
    {
        $this->checkoutSession->method('getData')
            ->with(SessionKeys::QUEUED_DISCOUNT_MESSAGES, true)
            ->willReturn(null);

        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testEmptyQueuedMessagesAddsNothing(): void
    {
        $this->checkoutSession->method('getData')
            ->with(SessionKeys::QUEUED_DISCOUNT_MESSAGES, true)
            ->willReturn([]);

        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testQueuedWarningMessageIsDisplayed(): void
    {
        $this->checkoutSession->method('getData')
            ->with(SessionKeys::QUEUED_DISCOUNT_MESSAGES, true)
            ->willReturn([
                ['type' => 'warning', 'text' => 'Coupon not applied to Widget A'],
            ]);

        $this->messageManager->expects($this->once())
            ->method('addWarningMessage')
            ->with('Coupon not applied to Widget A');
        $this->messageManager->expects($this->never())->method('addNoticeMessage');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testQueuedNoticeMessageIsDisplayed(): void
    {
        $this->checkoutSession->method('getData')
            ->with(SessionKeys::QUEUED_DISCOUNT_MESSAGES, true)
            ->willReturn([
                ['type' => 'notice', 'text' => 'Coupon applied additional 5% discount'],
            ]);

        $this->messageManager->expects($this->never())->method('addWarningMessage');
        $this->messageManager->expects($this->once())
            ->method('addNoticeMessage')
            ->with('Coupon applied additional 5% discount');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testMixedQueuedMessagesAreDisplayed(): void
    {
        $this->checkoutSession->method('getData')
            ->with(SessionKeys::QUEUED_DISCOUNT_MESSAGES, true)
            ->willReturn([
                ['type' => 'warning', 'text' => 'Exclusion warning'],
                ['type' => 'notice', 'text' => 'Bypass notice'],
                ['type' => 'warning', 'text' => 'Another warning'],
            ]);

        $this->messageManager->expects($this->exactly(2))->method('addWarningMessage');
        $this->messageManager->expects($this->once())->method('addNoticeMessage');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testClearsProcessedProductIds(): void
    {
        $this->checkoutSession->method('getData')
            ->with(SessionKeys::QUEUED_DISCOUNT_MESSAGES, true)
            ->willReturn(null);

        $this->checkoutSession->expects($this->once())
            ->method('unsetData')
            ->with(SessionKeys::PROCESSED_PRODUCT_IDS);

        $this->observer->execute($this->createMock(Observer::class));
    }
}

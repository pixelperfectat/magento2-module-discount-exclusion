<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Observer;

use Magento\Checkout\Controller\Cart\CouponPost;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\ExclusionMessageBuilderInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Observer\CouponPostObserver;
use PixelPerfect\DiscountExclusion\Service\ExclusionResultCollector;
use Psr\Log\LoggerInterface;

/**
 * Covers the Luma coupon-apply flow: message replacement and automatic coupon removal.
 *
 * Uses the real ExclusionResultCollector so the observer is exercised against the same
 * collector state the ValidatorPlugin produces during collectTotals().
 */
class CouponPostObserverTest extends TestCase
{
    private ExclusionResultCollector $resultCollector;
    private ExclusionMessageBuilderInterface&MockObject $messageBuilder;
    private ManagerInterface&MockObject $messageManager;
    private RequestInterface&MockObject $request;
    private CheckoutSession&MockObject $checkoutSession;
    private CartRepositoryInterface&MockObject $quoteRepository;
    private LoggerInterface&MockObject $logger;
    private ConfigInterface&MockObject $config;
    private Quote&MockObject $quote;
    private Address&MockObject $shippingAddress;
    private CouponPostObserver $observer;

    protected function setUp(): void
    {
        $this->resultCollector = new ExclusionResultCollector($this->createMock(LoggerInterface::class));
        $this->messageBuilder = $this->createMock(ExclusionMessageBuilderInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(ConfigInterface::class);

        $this->shippingAddress = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods(['getDiscountAmount'])
            ->getMock();

        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'collectTotals'])
            ->addMethods(['setCouponCode'])
            ->getMock();
        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $this->quote->method('setCouponCode')->willReturnSelf();
        $this->quote->method('collectTotals')->willReturnSelf();

        $this->checkoutSession->method('getQuote')->willReturn($this->quote);

        $this->observer = new CouponPostObserver(
            $this->resultCollector,
            $this->messageBuilder,
            $this->messageManager,
            $this->request,
            $this->checkoutSession,
            $this->quoteRepository,
            $this->logger,
            $this->config,
        );
    }

    public function testIgnoresOtherControllerActions(): void
    {
        $this->addExcludedItem('SAVE10');

        $this->request->expects($this->never())->method('getParam');
        $this->quoteRepository->expects($this->never())->method('save');
        $this->messageBuilder->expects($this->never())->method('addMessagesForCoupon');

        $this->observer->execute($this->createEventObserver($this->createMock(Action::class)));

        $this->assertTrue(
            $this->resultCollector->hasExcludedItems('SAVE10'),
            'Results must survive requests that are not the coupon-apply action'
        );
    }

    public function testIgnoresEventWithoutControllerAction(): void
    {
        $this->addExcludedItem('SAVE10');

        $this->request->expects($this->never())->method('getParam');

        $this->observer->execute(new Observer(['event' => new Event([])]));

        $this->assertTrue($this->resultCollector->hasExcludedItems('SAVE10'));
    }

    public function testCouponRemovalRequestOnlyClearsCollector(): void
    {
        $this->addExcludedItem('SAVE10');
        $this->givenCouponParam('   ');

        $this->quoteRepository->expects($this->never())->method('save');
        $this->messageManager->expects($this->never())->method('getMessages');
        $this->messageBuilder->expects($this->never())->method('addMessagesForCoupon');

        $this->observer->execute($this->createCouponPostObserver());

        $this->assertFalse($this->resultCollector->hasExcludedItems('SAVE10'));
    }

    public function testCouponWithoutExcludedOrBypassedItemsKeepsMagentoMessages(): void
    {
        $this->addExcludedItem('OTHERCODE');
        $this->givenCouponParam('SAVE10');
        $this->config->method('isMessagesEnabled')->willReturn(true);

        $this->quote->expects($this->never())->method('setCouponCode');
        $this->quoteRepository->expects($this->never())->method('save');
        $this->messageManager->expects($this->never())->method('getMessages');
        $this->messageBuilder->expects($this->never())->method('addMessagesForCoupon');

        $this->observer->execute($this->createCouponPostObserver());

        $this->assertFalse(
            $this->resultCollector->hasExcludedItems('OTHERCODE'),
            'Collector is reset for the next request'
        );
    }

    public function testRemovesCouponWhenEveryItemWasExcluded(): void
    {
        $this->addExcludedItem('SAVE10');
        $this->givenCouponParam('SAVE10');
        $this->givenShippingDiscount(0.0);
        $this->config->method('isMessagesEnabled')->willReturn(true);

        $this->quote->expects($this->once())->method('setCouponCode')->with('')->willReturnSelf();
        $this->quote->expects($this->once())->method('collectTotals')->willReturnSelf();
        $this->quoteRepository->expects($this->once())->method('save')->with($this->quote);
        $this->logger->expects($this->once())
            ->method('info')
            ->with('DiscountExclusion: No actual discount applied, removing coupon from quote');

        $this->observer->execute($this->createCouponPostObserver());

        $this->assertFalse($this->resultCollector->hasExcludedItems('SAVE10'));
    }

    public function testTrimsTheSubmittedCouponCode(): void
    {
        $this->addExcludedItem('SAVE10');
        $this->givenCouponParam("  SAVE10\n");
        $this->givenShippingDiscount(0.0);
        $this->config->method('isMessagesEnabled')->willReturn(true);

        $this->quoteRepository->expects($this->once())->method('save');
        $this->messageBuilder->expects($this->once())->method('addMessagesForCoupon')->with('SAVE10');

        $this->observer->execute($this->createCouponPostObserver());
    }

    public function testReplacesMagentoMessagesWithExclusionMessages(): void
    {
        $this->addExcludedItem('SAVE10');
        $this->givenCouponParam('SAVE10');
        $this->givenShippingDiscount(0.0);
        $this->config->method('isMessagesEnabled')->willReturn(true);

        $calls = [];
        $this->messageManager->expects($this->once())
            ->method('getMessages')
            ->with(true)
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'clear-magento-messages';
                return null;
            });
        $this->messageBuilder->expects($this->once())
            ->method('addMessagesForCoupon')
            ->with('SAVE10')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'add-exclusion-messages';
            });

        $this->observer->execute($this->createCouponPostObserver());

        $this->assertSame(['clear-magento-messages', 'add-exclusion-messages'], $calls);
    }

    public function testKeepsMagentoMessagesWhenCustomerMessagesAreDisabled(): void
    {
        $this->addExcludedItem('SAVE10');
        $this->givenCouponParam('SAVE10');
        $this->givenShippingDiscount(0.0);
        $this->config->method('isMessagesEnabled')->willReturn(false);

        $this->messageManager->expects($this->never())->method('getMessages');
        $this->messageBuilder->expects($this->never())->method('addMessagesForCoupon');
        $this->quoteRepository->expects($this->once())->method('save');

        $this->observer->execute($this->createCouponPostObserver());

        $this->assertFalse($this->resultCollector->hasExcludedItems('SAVE10'));
    }

    /**
     * Magento stores address discounts as negative numbers; the sign must not matter.
     *
     * @dataProvider remainingDiscountProvider
     */
    public function testKeepsCouponWhenOtherItemsStillGetADiscount(float $shippingDiscount): void
    {
        $this->addExcludedItem('SAVE10');
        $this->givenCouponParam('SAVE10');
        $this->givenShippingDiscount($shippingDiscount);
        $this->config->method('isMessagesEnabled')->willReturn(true);

        $this->quote->expects($this->never())->method('setCouponCode');
        $this->quoteRepository->expects($this->never())->method('save');
        $this->messageBuilder->expects($this->once())->method('addMessagesForCoupon')->with('SAVE10');

        $this->observer->execute($this->createCouponPostObserver());
    }

    /**
     * @return array<string, array{float}>
     */
    public static function remainingDiscountProvider(): array
    {
        return [
            'negative address discount' => [-4.5],
            'positive address discount' => [4.5],
            'exactly one cent' => [-0.01],
        ];
    }

    public function testRemovesCouponWhenRemainingDiscountIsBelowOneCent(): void
    {
        $this->addExcludedItem('SAVE10');
        $this->givenCouponParam('SAVE10');
        $this->givenShippingDiscount(-0.009);

        $this->quoteRepository->expects($this->once())->method('save');

        $this->observer->execute($this->createCouponPostObserver());
    }

    public function testRemovesCouponWhenEveryBypassedItemKeptItsBetterExistingDiscount(): void
    {
        $this->addBypassedItem('BYPASS20', '1', BypassResultType::EXISTING_BETTER);
        $this->addBypassedItem('BYPASS20', '2', BypassResultType::EXISTING_BETTER);
        $this->givenCouponParam('BYPASS20');
        $this->givenShippingDiscount(0.0);

        $this->quote->expects($this->once())->method('setCouponCode')->with('');
        $this->quoteRepository->expects($this->once())->method('save');

        $this->observer->execute($this->createCouponPostObserver());
    }

    public function testKeepsCouponWhenAnyBypassedItemWasAdjusted(): void
    {
        $this->addBypassedItem('BYPASS30', '1', BypassResultType::EXISTING_BETTER);
        $this->addBypassedItem('BYPASS30', '2', BypassResultType::ADJUSTED);
        $this->givenCouponParam('BYPASS30');
        $this->givenShippingDiscount(0.0);
        $this->config->method('isMessagesEnabled')->willReturn(true);

        $this->quote->expects($this->never())->method('setCouponCode');
        $this->quoteRepository->expects($this->never())->method('save');
        $this->messageBuilder->expects($this->once())->method('addMessagesForCoupon')->with('BYPASS30');

        $this->observer->execute($this->createCouponPostObserver());

        $this->assertFalse($this->resultCollector->hasBypassedItems('BYPASS30'));
    }

    /**
     * Characterization of a known limitation (README "Known limitations", coupon auto-removal):
     * only the shipping address discount is read. For a virtual-only cart Magento puts the
     * discount on the billing address, so the coupon is removed even if it discounted something.
     */
    public function testOnlyTheShippingAddressDiscountIsConsidered(): void
    {
        $billingAddress = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods(['getDiscountAmount'])
            ->getMock();
        $billingAddress->method('getDiscountAmount')->willReturn(-5.0);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getBillingAddress', 'collectTotals'])
            ->addMethods(['setCouponCode'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $quote->method('getBillingAddress')->willReturn($billingAddress);
        $quote->method('setCouponCode')->willReturnSelf();
        $quote->method('collectTotals')->willReturnSelf();
        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willReturn($quote);

        $observer = new CouponPostObserver(
            $this->resultCollector,
            $this->messageBuilder,
            $this->messageManager,
            $this->request,
            $checkoutSession,
            $this->quoteRepository,
            $this->logger,
            $this->config,
        );

        $this->addExcludedItem('SAVE10');
        $this->givenCouponParam('SAVE10');
        $this->givenShippingDiscount(0.0);

        $this->quoteRepository->expects($this->once())->method('save')->with($quote);

        $observer->execute($this->createCouponPostObserver());
    }

    private function givenCouponParam(string $couponCode): void
    {
        $this->request->method('getParam')->with('coupon_code', '')->willReturn($couponCode);
    }

    private function givenShippingDiscount(float $amount): void
    {
        $this->shippingAddress->method('getDiscountAmount')->willReturn($amount);
    }

    private function createCouponPostObserver(): Observer
    {
        return $this->createEventObserver($this->createMock(CouponPost::class));
    }

    private function createEventObserver(object $controllerAction): Observer
    {
        return new Observer(['event' => new Event(['controller_action' => $controllerAction])]);
    }

    private function addExcludedItem(string $couponCode): void
    {
        $this->resultCollector->addExcludedItem($this->createItem('1'), 'Product is already discounted', $couponCode);
    }

    private function addBypassedItem(string $couponCode, string $productId, BypassResultType $type): void
    {
        $this->resultCollector->addBypassedItem($this->createItem($productId), $type, $couponCode);
    }

    private function createItem(string $productId): Quote\Item\AbstractItem
    {
        $product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $product->method('getId')->willReturn($productId);
        $product->method('getName')->willReturn('Product ' . $productId);

        $item = $this->getMockForAbstractClass(
            Quote\Item\AbstractItem::class,
            [],
            '',
            false,
            true,
            true,
            ['getProduct']
        );
        $item->method('getProduct')->willReturn($product);

        return $item;
    }
}

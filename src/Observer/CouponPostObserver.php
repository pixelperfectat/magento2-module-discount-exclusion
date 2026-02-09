<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Observer;

use Magento\Checkout\Controller\Cart\CouponPost;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\ExclusionMessageBuilderInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use Psr\Log\LoggerInterface;

class CouponPostObserver implements ObserverInterface
{
    public function __construct(
        private readonly ExclusionResultCollectorInterface $resultCollector,
        private readonly ExclusionMessageBuilderInterface $messageBuilder,
        private readonly ManagerInterface $messageManager,
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $controllerAction = $observer->getEvent()->getControllerAction();

        $this->logger->debug('DiscountExclusion: CouponPostObserver triggered', [
            'controller_class' => $controllerAction ? get_class($controllerAction) : 'null',
        ]);

        if (!($controllerAction instanceof CouponPost)) {
            $this->logger->debug('DiscountExclusion: Not a CouponPost action, skipping');
            return;
        }

        $couponCode = trim((string) $this->request->getParam('coupon_code', ''));

        if ($couponCode === '') {
            $this->resultCollector->clear();
            return;
        }

        $hasExcluded = $this->resultCollector->hasExcludedItems($couponCode);
        $hasBypassed = $this->resultCollector->hasBypassedItems($couponCode);

        $this->logger->debug('DiscountExclusion: Checking for results', [
            'coupon_code' => $couponCode,
            'has_excluded' => $hasExcluded,
            'has_bypassed' => $hasBypassed,
        ]);

        if (!$hasExcluded && !$hasBypassed) {
            $this->logger->debug('DiscountExclusion: No excluded or bypassed items for this coupon');
            $this->resultCollector->clear();
            return;
        }

        $quote = $this->checkoutSession->getQuote();
        $discountAmount = abs((float) $quote->getShippingAddress()->getDiscountAmount());

        // Determine if coupon should be removed:
        // Remove when ALL items are either excluded or existing_better (no actual discount applied)
        $shouldRemoveCoupon = $this->shouldRemoveCoupon($couponCode, $discountAmount);

        if ($shouldRemoveCoupon) {
            $this->logger->info('DiscountExclusion: No actual discount applied, removing coupon from quote');
            $quote->setCouponCode('')->collectTotals();
            $this->quoteRepository->save($quote);
        }

        // Clear ALL existing messages (including Magento's generic messages)
        $this->messageManager->getMessages(true);

        $this->messageBuilder->addMessagesForCoupon($couponCode);
        $this->resultCollector->clear();
    }

    /**
     * Determine if coupon should be removed from quote
     *
     * @param string $couponCode
     * @param float  $discountAmount
     *
     * @return bool
     */
    private function shouldRemoveCoupon(string $couponCode, float $discountAmount): bool
    {
        // If there's a meaningful discount amount, keep the coupon
        if ($discountAmount >= 0.01) {
            return false;
        }

        // No discount applied — check if all bypassed items are existing_better
        $bypassedItems = $this->resultCollector->getBypassedItems($couponCode);
        foreach ($bypassedItems as $item) {
            if ($item['type'] === BypassResultType::ADJUSTED) {
                // An adjusted item means some discount was applied
                return false;
            }
        }

        return true;
    }
}

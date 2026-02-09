<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionMessageBuilderInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;
use Psr\Log\LoggerInterface;

/**
 * Queues exclusion/bypass messages for display on the cart page
 *
 * Fires on cart add, update, and delete actions. The ValidatorPlugin has already
 * populated the collector during collectTotals() — this observer builds the messages
 * and queues them in the session for CartPageLoadObserver to display.
 */
class CartUpdateObserver implements ObserverInterface
{
    public function __construct(
        private readonly ExclusionResultCollectorInterface $resultCollector,
        private readonly ExclusionMessageBuilderInterface $messageBuilder,
        private readonly CheckoutSession $checkoutSession,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $couponCode = $this->getActiveCouponCode();

        if ($couponCode === null) {
            $this->resultCollector->clear();
            return;
        }

        $hasExcluded = $this->resultCollector->hasExcludedItems($couponCode);
        $hasBypassed = $this->resultCollector->hasBypassedItems($couponCode);

        if (!$hasExcluded && !$hasBypassed) {
            $this->resultCollector->clear();
            return;
        }

        $messages = $this->messageBuilder->buildMessagesForCoupon($couponCode);

        if (!empty($messages)) {
            $this->logger->debug('DiscountExclusion: CartUpdateObserver queuing messages for cart page', [
                'coupon_code' => $couponCode,
                'message_count' => count($messages),
            ]);
            $this->checkoutSession->setData(SessionKeys::QUEUED_DISCOUNT_MESSAGES, $messages);
        }

        $this->resultCollector->clear();
    }

    /**
     * Get the active coupon code from the quote
     *
     * @return string|null
     */
    private function getActiveCouponCode(): ?string
    {
        $quote = $this->checkoutSession->getQuote();
        $couponCode = $quote->getCouponCode();

        if ($couponCode !== null && $couponCode !== '') {
            return $couponCode;
        }

        return null;
    }
}

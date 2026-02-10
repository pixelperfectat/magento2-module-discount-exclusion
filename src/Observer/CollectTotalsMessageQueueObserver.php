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
 * Queues exclusion/bypass messages during GraphQL add-to-cart requests
 *
 * GraphQL mutations bypass controller dispatch events, so CartUpdateObserver
 * never fires. This observer listens to sales_quote_collect_totals_after
 * (scoped to graphql area) and queues messages into the checkout session
 * for CartPageLoadObserver to display when the cart page loads.
 */
class CollectTotalsMessageQueueObserver implements ObserverInterface
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
        /** @var \Magento\Quote\Model\Quote|null $quote */
        $quote = $observer->getEvent()->getQuote();
        $couponCode = $quote?->getCouponCode();

        if ($couponCode === null || $couponCode === '') {
            $this->resultCollector->clear();
            return;
        }

        if (!$this->resultCollector->hasExcludedItems($couponCode)
            && !$this->resultCollector->hasBypassedItems($couponCode)) {
            $this->resultCollector->clear();
            return;
        }

        $messages = $this->messageBuilder->buildMessagesForCoupon($couponCode);

        if (!empty($messages)) {
            $this->logger->debug('DiscountExclusion: CollectTotalsMessageQueueObserver queuing messages', [
                'coupon_code' => $couponCode,
                'message_count' => count($messages),
            ]);
            $this->checkoutSession->setData(SessionKeys::QUEUED_DISCOUNT_MESSAGES, $messages);
        }

        $this->resultCollector->clear();
    }
}

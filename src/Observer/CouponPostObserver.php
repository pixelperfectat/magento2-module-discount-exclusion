<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Observer;

use Magento\Checkout\Controller\Cart\CouponPost;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use Psr\Log\LoggerInterface;

class CouponPostObserver implements ObserverInterface
{
    public function __construct(
        private readonly ExclusionResultCollectorInterface $resultCollector,
        private readonly ManagerInterface $messageManager,
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly PriceCurrencyInterface $priceCurrency,
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

        // Process exclusion messages
        if ($hasExcluded) {
            $this->addExclusionMessages($couponCode);
        }

        // Process bypass messages
        if ($hasBypassed) {
            $this->addBypassMessages($couponCode);
        }

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

    /**
     * Add exclusion warning messages
     *
     * @param string $couponCode
     */
    private function addExclusionMessages(string $couponCode): void
    {
        $excludedItems = $this->resultCollector->getExcludedItems($couponCode);
        $productNames = array_map(
            fn(array $item) => $item['name'],
            $excludedItems
        );

        if (count($productNames) === 1) {
            $message = __(
                'Coupon "%1" was not applied to "%2" because it is already discounted.',
                $couponCode,
                reset($productNames)
            );
        } else {
            $message = __(
                'Coupon "%1" was not applied to the following products because they are already discounted: %2',
                $couponCode,
                implode(', ', $productNames)
            );
        }

        $this->messageManager->addWarningMessage((string) $message);
    }

    /**
     * Add bypass-related messages (adjusted and existing_better)
     *
     * @param string $couponCode
     */
    private function addBypassMessages(string $couponCode): void
    {
        $bypassedItems = $this->resultCollector->getBypassedItems($couponCode);

        foreach ($bypassedItems as $itemData) {
            $productName = $itemData['name'];
            $type = $itemData['type'];
            $params = $itemData['messageParams'];
            $simpleAction = $params['simpleAction'] ?? '';

            $message = $this->buildBypassMessage($couponCode, $productName, $type, $simpleAction, $params);

            if ($message === null) {
                continue;
            }

            if ($type === BypassResultType::ADJUSTED) {
                $this->messageManager->addNoticeMessage((string) $message);
            } else {
                $this->messageManager->addWarningMessage((string) $message);
            }
        }
    }

    /**
     * Build the appropriate bypass message based on type and action
     *
     * @param string                    $couponCode
     * @param string                    $productName
     * @param BypassResultType          $type
     * @param string                    $simpleAction
     * @param array<string, float|string> $params
     *
     * @return \Magento\Framework\Phrase|null
     */
    private function buildBypassMessage(
        string $couponCode,
        string $productName,
        BypassResultType $type,
        string $simpleAction,
        array $params,
    ): ?\Magento\Framework\Phrase {
        if ($type === BypassResultType::ADJUSTED && $simpleAction === Rule::BY_PERCENT_ACTION) {
            return __(
                'Coupon "%1" applied an additional %2%% discount to "%3", adjusted from %4%% because it is already %5%% discounted.',
                $couponCode,
                number_format((float) ($params['additionalDiscountPercent'] ?? 0), 0),
                $productName,
                number_format((float) ($params['ruleDiscountPercent'] ?? 0), 0),
                number_format((float) ($params['existingDiscountPercent'] ?? 0), 0),
            );
        }

        if ($type === BypassResultType::ADJUSTED && $simpleAction === Rule::BY_FIXED_ACTION) {
            return __(
                'Coupon "%1" applied an additional %2 discount to "%3", adjusted from %4 because it is already discounted by %5.',
                $couponCode,
                $this->formatPrice((float) ($params['additionalDiscountAmount'] ?? 0)),
                $productName,
                $this->formatPrice((float) ($params['ruleDiscountAmount'] ?? 0)),
                $this->formatPrice((float) ($params['existingDiscountAmount'] ?? 0)),
            );
        }

        if ($type === BypassResultType::EXISTING_BETTER && $simpleAction === Rule::BY_PERCENT_ACTION) {
            return __(
                'Coupon "%1" was not applied to "%2" because the existing %3%% discount already exceeds the coupon\'s %4%% discount.',
                $couponCode,
                $productName,
                number_format((float) ($params['existingDiscountPercent'] ?? 0), 0),
                number_format((float) ($params['ruleDiscountPercent'] ?? 0), 0),
            );
        }

        if ($type === BypassResultType::EXISTING_BETTER && $simpleAction === Rule::BY_FIXED_ACTION) {
            return __(
                'Coupon "%1" was not applied to "%2" because the existing %3 discount already exceeds the coupon\'s %4 discount.',
                $couponCode,
                $productName,
                $this->formatPrice((float) ($params['existingDiscountAmount'] ?? 0)),
                $this->formatPrice((float) ($params['ruleDiscountAmount'] ?? 0)),
            );
        }

        return null;
    }

    /**
     * Format a price using the store's currency
     *
     * @param float $amount
     *
     * @return string
     */
    private function formatPrice(float $amount): string
    {
        return $this->priceCurrency->format($amount, false);
    }
}

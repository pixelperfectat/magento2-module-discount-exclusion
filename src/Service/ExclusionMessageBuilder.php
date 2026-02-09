<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\ExclusionMessageBuilderInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;

class ExclusionMessageBuilder implements ExclusionMessageBuilderInterface
{
    public function __construct(
        private readonly ExclusionResultCollectorInterface $resultCollector,
        private readonly ManagerInterface $messageManager,
        private readonly PriceCurrencyInterface $priceCurrency,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function addMessagesForCoupon(string $couponCode): void
    {
        if ($this->resultCollector->hasExcludedItems($couponCode)) {
            $this->addExclusionMessages($couponCode);
        }

        if ($this->resultCollector->hasBypassedItems($couponCode)) {
            $this->addBypassMessages($couponCode);
        }
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
     * @param string                      $couponCode
     * @param string                      $productName
     * @param BypassResultType            $type
     * @param string                      $simpleAction
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
                'Coupon "%1" applied an additional %2% discount to "%3", adjusted from %4% because it is already %5% discounted.',
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
                'Coupon "%1" was not applied to "%2" because the existing %3% discount already exceeds the coupon\'s %4% discount.',
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

<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Api\Data\BypassResultType;
use PixelPerfect\DiscountExclusion\Api\ExclusionMessageBuilderInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;

class ExclusionMessageBuilder implements ExclusionMessageBuilderInterface
{
    public function __construct(
        private readonly ExclusionResultCollectorInterface $resultCollector,
        private readonly ManagerInterface $messageManager,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ConfigInterface $config,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function addMessagesForCoupon(string $couponCode): void
    {
        if (!$this->config->isMessagesEnabled()) {
            return;
        }

        foreach ($this->buildMessagesForCoupon($couponCode) as $message) {
            if ($message['type'] === 'notice') {
                $this->messageManager->addNoticeMessage($message['text']);
            } else {
                $this->messageManager->addWarningMessage($message['text']);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function buildMessagesForCoupon(string $couponCode): array
    {
        if (!$this->config->isMessagesEnabled()) {
            return [];
        }

        $messages = [];

        if ($this->resultCollector->hasExcludedItems($couponCode)) {
            $messages = array_merge($messages, $this->buildExclusionMessages($couponCode));
        }

        if ($this->resultCollector->hasBypassedItems($couponCode)) {
            $messages = array_merge($messages, $this->buildBypassMessages($couponCode));
        }

        return $messages;
    }

    /**
     * Build exclusion warning messages
     *
     * @param string $couponCode
     *
     * @return array<int, array{type: string, text: string}>
     */
    private function buildExclusionMessages(string $couponCode): array
    {
        $excludedItems = $this->resultCollector->getExcludedItems($couponCode);
        $productNames = array_map(
            fn(array $item) => $item['name'],
            $excludedItems
        );

        if (count($productNames) === 1) {
            $text = (string) __(
                'Coupon "%1" was not applied to "%2" because it is already discounted.',
                $couponCode,
                reset($productNames)
            );
        } else {
            $text = (string) __(
                'Coupon "%1" was not applied to the following products because they are already discounted: %2',
                $couponCode,
                implode(', ', $productNames)
            );
        }

        return [['type' => 'warning', 'text' => $text]];
    }

    /**
     * Build bypass-related messages (adjusted and existing_better)
     *
     * @param string $couponCode
     *
     * @return array<int, array{type: string, text: string}>
     */
    private function buildBypassMessages(string $couponCode): array
    {
        $messages = [];
        $bypassedItems = $this->resultCollector->getBypassedItems($couponCode);

        foreach ($bypassedItems as $itemData) {
            $productName = $itemData['name'];
            $type = $itemData['type'];
            $params = $itemData['messageParams'];
            $simpleAction = $params['simpleAction'] ?? '';

            $phrase = $this->buildBypassPhrase($couponCode, $productName, $type, $simpleAction, $params);

            if ($phrase === null) {
                continue;
            }

            $messages[] = [
                'type' => $type === BypassResultType::ADJUSTED ? 'notice' : 'warning',
                'text' => (string) $phrase,
            ];
        }

        return $messages;
    }

    /**
     * Build the appropriate bypass phrase based on type and action
     *
     * @param string                      $couponCode
     * @param string                      $productName
     * @param BypassResultType            $type
     * @param string                      $simpleAction
     * @param array<string, float|string> $params
     *
     * @return \Magento\Framework\Phrase|null
     */
    private function buildBypassPhrase(
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

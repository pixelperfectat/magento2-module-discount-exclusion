<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Checkout\Model\Session;
use Magento\Framework\Message\ManagerInterface;
use PixelPerfect\DiscountExclusion\Api\MessageProcessorInterface;
use PixelPerfect\DiscountExclusion\Model\MessageGroups;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;

class MessageProcessor implements MessageProcessorInterface
{
    public function __construct(
        private readonly ManagerInterface $messageManager,
        private readonly Session $checkoutSession
    ) {
    }

    public function processDiscountExclusionMessages(): int
    {
        $exclusionMessages = $this->messageManager->getMessages(true, MessageGroups::DISCOUNT_EXCLUSION);

        if (!$exclusionMessages || $exclusionMessages->getCount() === 0) {
            return 0;
        }

        // Clear ALL existing messages (including the generic coupon error)
        $this->messageManager->getMessages()->clear();

        $processedCount = 0;

        // Add only the specific exclusion messages
        foreach ($exclusionMessages->getItems() as $exclusionMessage) {
            $this->messageManager->addErrorMessage($exclusionMessage->getText());
            $processedCount++;
        }

        // Clear session data since messages have been processed
        $this->checkoutSession->unsetData(SessionKeys::PROCESSED_PRODUCT_IDS);

        return $processedCount;
    }

    public function hasDiscountExclusionMessages(): bool
    {
        $exclusionMessages = $this->messageManager->getMessages(false, MessageGroups::DISCOUNT_EXCLUSION);
        return $exclusionMessages && $exclusionMessages->getCount() > 0;
    }
}

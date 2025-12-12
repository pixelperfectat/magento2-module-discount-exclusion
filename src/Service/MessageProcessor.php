<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Checkout\Model\Session;
use Magento\Framework\Message\ManagerInterface;
use PixelPerfect\DiscountExclusion\Api\MessageProcessorInterface;
use PixelPerfect\DiscountExclusion\Model\MessageGroups;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;
use Psr\Log\LoggerInterface;

class MessageProcessor implements MessageProcessorInterface
{
    public function __construct(
        private readonly ManagerInterface $messageManager,
        private readonly Session $checkoutSession,
        private readonly LoggerInterface $logger
    ) {
    }

    public function processDiscountExclusionMessages(): int
    {
        $this->logger->debug('DiscountExclusion: MessageProcessor::processDiscountExclusionMessages called');

        $exclusionMessages = $this->messageManager->getMessages(true, MessageGroups::DISCOUNT_EXCLUSION);

        if (!$exclusionMessages || $exclusionMessages->getCount() === 0) {
            $this->logger->debug('DiscountExclusion: No exclusion messages found in group');
            return 0;
        }

        $this->logger->debug('DiscountExclusion: Found exclusion messages', [
            'count' => $exclusionMessages->getCount(),
        ]);

        $processedCount = 0;

        // Add the specific exclusion messages as warnings (not errors)
        foreach ($exclusionMessages->getItems() as $exclusionMessage) {
            $messageText = $exclusionMessage->getText();
            $this->logger->debug('DiscountExclusion: Adding message', [
                'message' => $messageText,
            ]);
            $this->messageManager->addWarningMessage($messageText);
            $processedCount++;
        }

        // Clear session data since messages have been processed
        $this->checkoutSession->unsetData(SessionKeys::PROCESSED_PRODUCT_IDS);

        $this->logger->info('DiscountExclusion: Processed exclusion messages', [
            'count' => $processedCount,
        ]);

        return $processedCount;
    }

    public function hasDiscountExclusionMessages(): bool
    {
        $exclusionMessages = $this->messageManager->getMessages(false, MessageGroups::DISCOUNT_EXCLUSION);
        $hasMessages = $exclusionMessages && $exclusionMessages->getCount() > 0;

        $this->logger->debug('DiscountExclusion: hasDiscountExclusionMessages check', [
            'has_messages' => $hasMessages,
            'count' => $exclusionMessages ? $exclusionMessages->getCount() : 0,
        ]);

        return $hasMessages;
    }
}

<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;

/**
 * Displays queued discount exclusion messages on cart page load
 *
 * Messages are queued by CartUpdateObserver during add/update/delete actions
 * and displayed here when the cart page is loaded.
 */
class CartPageLoadObserver implements ObserverInterface
{
    public function __construct(
        private readonly Session $checkoutSession,
        private readonly ManagerInterface $messageManager,
    ) {
    }

    public function execute(Observer $observer): void
    {
        // Display queued discount exclusion messages
        $messages = $this->checkoutSession->getData(SessionKeys::QUEUED_DISCOUNT_MESSAGES, true);

        if (!empty($messages)) {
            foreach ($messages as $message) {
                if ($message['type'] === 'notice') {
                    $this->messageManager->addNoticeMessage($message['text']);
                } else {
                    $this->messageManager->addWarningMessage($message['text']);
                }
            }
        }

        // Clear processed product IDs
        $this->checkoutSession->unsetData(SessionKeys::PROCESSED_PRODUCT_IDS);
    }
}

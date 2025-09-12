<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use PixelPerfect\DiscountExclusion\Api\MessageProcessorInterface;

class CouponPostObserver implements ObserverInterface
{
    public function __construct(
        private readonly MessageProcessorInterface $messageProcessor,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        $controllerAction = $observer->getEvent()->getControllerAction();

        if (!($controllerAction instanceof \Magento\Checkout\Controller\Cart\CouponPost)) {
            return;
        }

        // If we have exclusion messages, clear everything and replace
        if ($this->messageProcessor->hasDiscountExclusionMessages()) {
            // Clear ALL messages first (before they become cookies)
            $this->messageManager->getMessages()->clear();

            // Process our exclusion messages
            $this->messageProcessor->processDiscountExclusionMessages();
        }
    }
}

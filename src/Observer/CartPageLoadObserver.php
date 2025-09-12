<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;

/**
 * Observer to clear processed product IDs from session when cart page is loaded
 */
class CartPageLoadObserver implements ObserverInterface
{
    public function __construct(
        private readonly Session $checkoutSession
    ) {
    }

    public function execute(Observer $observer): void
    {
        $request = $observer->getEvent()->getRequest();
        $controllerAction = $observer->getEvent()->getControllerAction();

        // Clear session data when cart page is loaded
        if ($controllerAction instanceof \Magento\Checkout\Controller\Cart\Index) {
            $this->checkoutSession->unsetData(SessionKeys::PROCESSED_PRODUCT_IDS);
        }
    }
}

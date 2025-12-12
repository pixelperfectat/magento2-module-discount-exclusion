<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Observer;

use Magento\Checkout\Controller\Cart\CouponPost;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use PixelPerfect\DiscountExclusion\Api\ExclusionResultCollectorInterface;
use Psr\Log\LoggerInterface;

class CouponPostObserver implements ObserverInterface
{
    public function __construct(
        private readonly ExclusionResultCollectorInterface $resultCollector,
        private readonly ManagerInterface $messageManager,
        private readonly RequestInterface $request,
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

        // Get the coupon code that was submitted
        $couponCode = trim((string) $this->request->getParam('coupon_code', ''));

        $this->logger->debug('DiscountExclusion: Checking for excluded items', [
            'coupon_code' => $couponCode,
            'has_any_excluded' => $this->resultCollector->hasAnyExcludedItems(),
        ]);

        // Check if we have excluded items for this coupon
        if ($couponCode === '' || !$this->resultCollector->hasExcludedItems($couponCode)) {
            $this->logger->debug('DiscountExclusion: No excluded items for this coupon');
            $this->resultCollector->clear();
            return;
        }

        $excludedItems = $this->resultCollector->getExcludedItems($couponCode);

        $this->logger->info('DiscountExclusion: Found excluded items, replacing messages', [
            'coupon_code' => $couponCode,
            'excluded_count' => count($excludedItems),
        ]);

        // Clear ALL existing messages (including Magento's generic "coupon not valid" error)
        $this->messageManager->getMessages(true);

        // Build and add our custom message(s)
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

        $this->logger->debug('DiscountExclusion: Adding warning message', [
            'message' => (string) $message,
        ]);

        $this->messageManager->addWarningMessage((string) $message);

        // Clear collector for next request
        $this->resultCollector->clear();
    }
}

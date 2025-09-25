<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Model\Validator;

use Laminas\Validator\ValidatorInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Model\MessageGroups;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;

class DiscountValidator implements ValidatorInterface
{
    /**
     * @var array<string, string>
     */
    private array $messages = [];

    public function __construct(
        private readonly DiscountExclusionManagerInterface $discountExclusionManager,
        private readonly ManagerInterface                  $messageManager,
        private readonly RequestInterface                  $request,
        private readonly Session                           $checkoutSession
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @inheritDoc
     */
    public function isValid($value): bool
    {
        // Clear previous messages
        $this->messages = [];

        if (!$value instanceof AbstractItem) {
            return true;
        }

        // Check if the item is a child of a complex item
        /** @var Item $value */
        if ($value->getParentItem()) {
            return false;
        }

        // either a simple item or a complex item
        $product  = $value->getProduct();
        $children = $value->getChildren();
        if (count($children) > 0 && $value->getChildren()[0]->getProduct()) {
            $product = $value->getChildren()[0]->getProduct();
        }

        $couponCode = $this->request->getParam('coupon_code');
        if ($couponCode === null) {
            $couponCode = $this->checkoutSession->getQuote()->getCouponCode();
        }

        // Check if the product should be excluded from additional discounts
        $shouldExclude = $this->discountExclusionManager->shouldExcludeFromDiscount(
            $product,
            $value,
            $couponCode
        );

        if ($shouldExclude) {
            $productId = $product->getId();

            // Get processed product IDs from session
            $processedProductIds = $this->checkoutSession->getData(SessionKeys::PROCESSED_PRODUCT_IDS) ?: [];

            // Only add message if we haven't already processed this product
            if (!isset($processedProductIds[$productId])) {
                // Get the coupon code from the request
                if ($couponCode !== null) {
                    $message
                        = __(
                        'Coupon %2 was not applied to Product "%1" because it is already discounted.',
                        $value->getProduct()->getName(),
                        $couponCode
                    );

                    $this->messages[MessageGroups::DISCOUNT_EXCLUSION] = $message->render();

                    // Add to MessageManager with group (only once per product)
                    $this->messageManager->addMessage(
                        $this->messageManager->createMessage(MessageInterface::TYPE_ERROR)->setText($message->render()),
                        MessageGroups::DISCOUNT_EXCLUSION
                    );

                    // Mark this product as processed
                    $processedProductIds[$productId] = true;
                    $this->checkoutSession->setData(SessionKeys::PROCESSED_PRODUCT_IDS, $processedProductIds);
                }
            }

            return false;
        }

        return true;
    }
}

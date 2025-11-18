<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Validator;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface;
use PixelPerfect\DiscountExclusion\Model\MessageGroups;
use PixelPerfect\DiscountExclusion\Model\SessionKeys;

class ValidatorPlugin
{
    public function __construct(
        private readonly ConfigInterface                   $config,
        private readonly DiscountExclusionManagerInterface $discountExclusionManager,
        private readonly ManagerInterface                  $messageManager,
        private readonly RequestInterface                  $request,
        private readonly Session                           $checkoutSession
    ) {
    }

    /**
     * Around plugin for Validator::process to intercept discount validation
     *
     * @param Validator    $subject
     * @param callable     $proceed
     * @param AbstractItem $item
     * @param Rule         $rule
     *
     * @return Validator
     */
    public function aroundProcess(
        Validator $subject,
        callable $proceed,
        AbstractItem $item,
        Rule $rule
    ): Validator {
        // Early exit if module is disabled
        if (!$this->config->isEnabled($item->getStoreId())) {
            return $proceed($item, $rule);
        }

        // Skip child items of complex products
        if ($item->getParentItem()) {
            return $proceed($item, $rule);
        }

        // Get the actual product (handle configurable products by checking children)
        $product  = $item->getProduct();
        $children = $item->getChildren();
        if (count($children) > 0 && $children[0]->getProduct()) {
            $product = $children[0]->getProduct();
        }

        // Get processed product IDs from session
        $processedProductIds = $this->checkoutSession->getData(SessionKeys::PROCESSED_PRODUCT_IDS) ?: [];

        // Check if the product should be excluded from additional discounts
        $shouldExclude = $this->discountExclusionManager->shouldExcludeFromDiscount(
            $product,
            $item,
            $rule
        );

        if ($shouldExclude) {
            $productId = $product->getId();

            // Only add message if we haven't already processed this product
            if (!isset($processedProductIds[$productId])) {
                // Get the coupon code
                $couponCode = $this->request->getParam('coupon_code');
                if ($couponCode === null) {
                    $couponCode = $this->checkoutSession->getQuote()->getCouponCode();
                }

                if ($couponCode !== null) {
                    $message = __(
                        'Coupon %2 was not applied to Product "%1" because it is already discounted.',
                        $item->getProduct()->getName(),
                        $couponCode
                    );

                    // Add to MessageManager with group (only once per product)
                    $this->messageManager->addMessage(
                        $this->messageManager->createMessage(MessageInterface::TYPE_ERROR)->setText($message->render()),
                        MessageGroups::DISCOUNT_EXCLUSION
                    );
                }

                // Mark this product as processed
                $processedProductIds[$productId] = true;
                $this->checkoutSession->setData(SessionKeys::PROCESSED_PRODUCT_IDS, $processedProductIds);
            }

            // Return subject without calling proceed - this blocks the discount
            return $subject;
        }

        // Allow discount by calling proceed
        return $proceed($item, $rule);
    }
}

<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Model\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Store\Model\StoreManagerInterface;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionStrategyInterface;

class CatalogRuleStrategy implements DiscountExclusionStrategyInterface
{
    public function __construct(
        private readonly CatalogRuleResource   $catalogRuleResource,
        private readonly CustomerSession       $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface     $timezone
    ) {
    }

    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem             $item
    ): bool {
        $websiteId       = $this->storeManager->getStore()->getWebsiteId();
        $customerGroupId = $this->customerSession->getCustomerGroupId();
        $date            = $this->timezone->date()->getTimestamp();

        $rules = $this->catalogRuleResource->getRulesFromProduct(
            $date,
            $websiteId,
            $customerGroupId,
            $product->getId()
        );

        return !empty($rules);
    }
}
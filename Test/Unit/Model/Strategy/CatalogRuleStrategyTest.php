<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Model\Strategy;

use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Model\Strategy\CatalogRuleStrategy;

class CatalogRuleStrategyTest extends TestCase
{
    private CatalogRuleStrategy $strategy;
    private CatalogRuleResource&MockObject $catalogRuleResource;
    private CustomerSession&MockObject $customerSession;
    private StoreManagerInterface&MockObject $storeManager;
    private TimezoneInterface&MockObject $timezone;
    private AbstractItem&MockObject $item;

    protected function setUp(): void
    {
        $this->catalogRuleResource = $this->createMock(CatalogRuleResource::class);
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->item = $this->createMock(AbstractItem::class);

        // Setup default store
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        // Setup default customer group
        $this->customerSession->method('getCustomerGroupId')->willReturn(0);

        // Setup default timezone
        $dateTime = new \DateTime();
        $this->timezone->method('date')->willReturn($dateTime);

        $this->strategy = new CatalogRuleStrategy(
            $this->catalogRuleResource,
            $this->customerSession,
            $this->storeManager,
            $this->timezone
        );
    }

    public function testProductWithActiveCatalogRuleIsExcluded(): void
    {
        $product = $this->createProductMock(100, 29.99, 39.99, null);

        // Mock catalog rule exists for this product
        $this->catalogRuleResource->method('getRulesFromProduct')
            ->willReturn([['rule_id' => 1, 'name' => 'Test Rule']]);

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertTrue($result, 'Product with active catalog rule should be excluded');
    }

    public function testProductWithoutCatalogRuleIsNotExcluded(): void
    {
        $product = $this->createProductMock(100, 39.99, 39.99, null);

        // No catalog rules for this product
        $this->catalogRuleResource->method('getRulesFromProduct')
            ->willReturn([]);

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertFalse($result, 'Product without catalog rule should not be excluded');
    }

    public function testProductWithCatalogRuleIsExcludedRegardlessOfSpecialPrice(): void
    {
        // Implementation only checks if catalog rules exist, not which price wins
        $product = $this->createProductMock(100, 29.99, 39.99, 29.99);

        // Catalog rule exists for this product
        $this->catalogRuleResource->method('getRulesFromProduct')
            ->willReturn([['rule_id' => 1]]);

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertTrue($result, 'Product with catalog rule is excluded regardless of special price');
    }

    public function testProductWithCatalogRuleIsExcludedRegardlessOfPriceReduction(): void
    {
        // Final price equals regular price - no discount applied
        // But implementation only checks if rules exist
        $product = $this->createProductMock(100, 39.99, 39.99, null);

        // Catalog rule exists for this product
        $this->catalogRuleResource->method('getRulesFromProduct')
            ->willReturn([['rule_id' => 1]]);

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertTrue($result, 'Product with catalog rule is excluded regardless of price reduction');
    }

    public function testProductWithFinalPriceHigherThanRegularIsNotExcluded(): void
    {
        // Edge case: Final price somehow higher than regular
        $product = $this->createProductMock(100, 49.99, 39.99, null);

        $this->catalogRuleResource->method('getRulesFromProduct')
            ->willReturn([]);

        $result = $this->strategy->shouldExcludeFromDiscount($product, $this->item);

        $this->assertFalse($result, 'Product with final price higher than regular should not be excluded');
    }

    public function testCorrectParametersPassedToCatalogRuleResource(): void
    {
        $product = $this->createProductMock(123, 29.99, 39.99, null);

        $this->catalogRuleResource->expects($this->once())
            ->method('getRulesFromProduct')
            ->with(
                $this->isType('int'),  // timestamp
                1,                      // website ID
                0,                      // customer group ID
                123                     // product ID
            )
            ->willReturn([['rule_id' => 1]]);

        $this->strategy->shouldExcludeFromDiscount($product, $this->item);
    }

    /**
     * Create a mock product with price data
     */
    private function createProductMock(
        int $productId,
        float $finalPrice,
        float $regularPrice,
        ?float $specialPrice
    ): Product&MockObject {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($productId);
        $product->method('getFinalPrice')->willReturn($finalPrice);
        $product->method('getPrice')->willReturn($regularPrice);
        $product->method('getSpecialPrice')->willReturn($specialPrice);

        return $product;
    }
}

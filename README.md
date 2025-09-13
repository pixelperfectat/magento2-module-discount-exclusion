# PixelPerfect DiscountExclusion - Technical Overview

## Module Information
- **Name**: `PixelPerfect_DiscountExclusion`
- **Package**: `pixelperfectat/magento2-module-discount-exclusion`
- **Version**: 1.0.0
- **License**: MIT
- **Author**: André Flitsch (pixelhed@pixelperfect.at)

## Business Requirements

### Core Functionality
The module prevents additional shopping cart discounts (coupon codes) from being applied to products that already have existing discounts from:
- Special prices
- Catalog price rules

### Key Behaviors
- Products with existing discounts are excluded from further cart rule discounts
- Other non-discounted products in the cart can still receive discounts
- Free shipping promotions are preserved and continue to work
- Custom user-friendly error messages replace generic "coupon code is not valid" messages

## Architecture Overview

### Design Patterns
1. **Strategy Pattern**: Extensible discount exclusion rules
2. **Service Contracts**: Proper interfaces for extensibility
3. **Dependency Injection**: Clean separation of concerns
4. **Observer Pattern**: Event-driven message processing

### Core Components

#### 1. Service Contracts (APIs)
```
src/Api/
├── DiscountExclusionManagerInterface.php
├── DiscountExclusionStrategyInterface.php
└── MessageProcessorInterface.php
```

#### 2. Service Layer
```
src/Service/
├── DiscountExclusionManager.php
└── MessageProcessor.php
```

#### 3. Validation System
```
src/Model/Validator/
└── DiscountValidator.php
```

#### 4. Strategy Implementations
```
src/Model/Strategy/
├── SpecialPriceStrategy.php
└── CatalogRuleStrategy.php
```

#### 5. Event Observers
```
src/Observer/
└── CouponPostObserver.php
```

#### 6. Constants & Configuration
```
src/Model/
├── MessageGroups.php
└── SessionKeys.php
```

## Technical Implementation Details

### 1. Discount Validation Flow

#### Integration Point
- **Hook**: `Magento\SalesRule\Model\Validator\Pool`
- **Validator**: `DiscountValidator`
- **Trigger**: Called during `canApplyDiscount()` for each quote item

#### Validation Process
1. Check if item is a child of complex product (skip if true)
2. Determine actual product to validate (handle configurable products)
3. Run exclusion strategies against the product
4. Add custom messages to special message group if excluded
5. Return validation result (false = exclude discount)

### 2. Strategy Pattern Implementation

#### Base Interface
```php
interface DiscountExclusionStrategyInterface
{
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item
    ): bool;
}
```

#### Default Strategies
1. **SpecialPriceStrategy**: Excludes products with active special prices
2. **CatalogRuleStrategy**: Excludes products affected by catalog price rules

#### Extension Points
- Add custom strategies via DI configuration
- Strategies are automatically loaded and executed
- Easy to add new exclusion criteria without modifying core logic

### 3. Message Processing System

#### Message Groups
- **Custom Group**: `discount_exclusion` - Internal message storage
- **Default Group**: Standard Magento messages shown to users

#### Message Flow
1. Validator adds messages to `discount_exclusion` group
2. Session tracking prevents duplicate messages per product
3. Observer intercepts after controller execution
4. Messages moved from custom group to default group
5. Generic "coupon invalid" messages are cleared
6. Session tracking data is cleaned up

#### Observer Functions
- **CouponPostObserver**: Processes messages after coupon submission
- **CartPageLoadObserver**: Clears stale session data when cart page is loaded

#### Deduplication Strategy
- Session-based tracking: `SessionKeys::PROCESSED_PRODUCT_IDS`
- Prevents duplicate messages during multiple totals collection cycles
- Automatic cleanup after message display and on cart page reload

### 4. Complex Product Handling

#### Product Type Support
- **Simple Products**: Direct validation
- **Configurable Products**: Validates child product for pricing
- **Bundled Products**: Skips child items, validates parent
- **Grouped Products**: Individual product validation

#### Logic
```php
// Get actual product for validation
$product = $value->getProduct();
$children = $value->getChildren();
if (count($children) > 0 && $value->getChildren()[0]->getProduct()) {
    $product = $value->getChildren()[0]->getProduct(); // Use child for configurable
}
```

## Configuration & Setup

### 1. Dependency Injection (`di.xml`)

#### Service Contracts
```xml
<preference for="PixelPerfect\DiscountExclusion\Api\DiscountExclusionManagerInterface"
            type="PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager"/>
<preference for="PixelPerfect\DiscountExclusion\Api\MessageProcessorInterface"
            type="PixelPerfect\DiscountExclusion\Service\MessageProcessor"/>
```

#### Validator Registration
```xml
<type name="Magento\SalesRule\Model\Validator\Pool">
    <arguments>
        <argument name="validators" xsi:type="array">
            <item name="discount" xsi:type="array">
                <item name="discount_exclusion" xsi:type="object">
                    PixelPerfect\DiscountExclusion\Model\Validator\DiscountValidator
                </item>
            </item>
        </argument>
    </arguments>
</type>
```

#### Strategy Configuration
```xml
<type name="PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager">
    <arguments>
        <argument name="strategies" xsi:type="array">
            <item name="special_price" xsi:type="object">
                PixelPerfect\DiscountExclusion\Model\Strategy\SpecialPriceStrategy
            </item>
            <item name="catalog_rule" xsi:type="object">
                PixelPerfect\DiscountExclusion\Model\Strategy\CatalogRuleStrategy
            </item>
        </argument>
    </arguments>
</type>
```

### 2. Event Configuration (`events.xml`)
```xml
<event name="controller_action_postdispatch_checkout_cart_couponPost">
    <observer name="discount_exclusion_coupon_post" 
              instance="PixelPerfect\DiscountExclusion\Observer\CouponPostObserver"/>
</event>
```

### 3. Module Dependencies (`module.xml`)
```xml
<module name="PixelPerfect_DiscountExclusion">
    <sequence>
        <module name="Magento_Sales"/>
        <module name="Magento_SalesRule"/>
    </sequence>
</module>
```

## Data Flow

### 1. Coupon Application Process
```
1. User submits coupon code
2. CouponPost controller processes request
3. Quote totals collection triggered
4. DiscountValidator runs for each item
5. Strategies evaluate exclusion criteria
6. Messages added to custom group
7. Session tracks processed products
8. Observer intercepts post-controller
9. Messages moved to default group
10. Generic messages cleared
11. Custom messages displayed to user
```

### 2. Message Processing Flow
```
Validator → Custom Message Group → Session Tracking → Observer → Default Group → User Display
```

## Integration Points

### 1. Magento Core Integration
- **Sales Rule Validation**: `Magento\SalesRule\Model\Validator\Pool`
- **Message Management**: `Magento\Framework\Message\ManagerInterface`
- **Session Management**: `Magento\Checkout\Model\Session`
- **Controller Events**: `controller_action_postdispatch_*`

### 2. Extension Points
- **Custom Strategies**: Implement `DiscountExclusionStrategyInterface`
- **Custom Message Processing**: Extend `MessageProcessorInterface`
- **Additional Validation**: Plugin on `DiscountExclusionManager`

## Error Handling & Edge Cases

### 1. Complex Product Types
- Child items of configurable products are skipped
- Validation runs on actual pricing product
- Parent item logic for bundled products

### 2. Message Deduplication
- Session-based tracking prevents duplicates
- Automatic cleanup after display
- Request-scoped validation results

### 3. Free Shipping Preservation
- Free shipping rules bypass item-level validation
- Shipping discounts processed separately
- No interference with shipping promotions

## Performance Considerations

### 1. Validation Efficiency
- Early returns for non-applicable items
- Cached validation results per product
- Minimal database queries

### 2. Strategy Execution
- Lazy loading of strategies
- First-match-wins optimization
- No unnecessary processing

### 3. Session Management
- Minimal session data storage
- Automatic cleanup mechanisms
- Request-scoped caching

## Testing Strategy

### 1. Unit Tests
- Strategy validation logic
- Message processing functionality
- Service contract implementations

### 2. Integration Tests
- End-to-end coupon application
- Complex product scenarios
- Message display verification

### 3. Edge Case Testing
- Multiple discount combinations
- Product type variations
- Session persistence scenarios

## Deployment & Maintenance

### 1. Installation
```bash
composer require pixelperfectat/magento2-module-discount-exclusion
php bin/magento module:enable PixelPerfect_DiscountExclusion
php bin/magento setup:upgrade
php bin/magento cache:flush
```

### 2. Configuration
- No admin configuration required
- Extension via DI configuration only
- Automatic activation after installation

### 3. Monitoring
- Check session storage for cleanup
- Monitor message display accuracy
- Validate discount exclusion behavior

## Compatibility

### 1. Magento Versions
- **Minimum**: Magento 2.4.x
- **PHP**: 8.2+
- **Dependencies**: Core sales rule modules

### 2. Third-party Compatibility
- Compatible with standard discount extensions
- May require review with custom checkout solutions
- Standard Magento API usage ensures broad compatibility

## Future Enhancements

### 1. Potential Features
- Admin configuration interface
- Logging and reporting capabilities
- Additional default strategies
- GraphQL support

### 2. Extension Opportunities
- Custom exclusion criteria
- Advanced message customization
- Integration with external systems
- Performance optimizations

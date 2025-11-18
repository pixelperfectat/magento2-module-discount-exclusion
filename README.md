# PixelPerfect Discount Exclusion

Extensible Magento 2 module that prevents applying shopping cart (sales rule) discounts to products already discounted by other mechanisms.

Status: Active development. APIs and behavior may change without backward compatibility.

## What it does

- Blocks additional cart discounts when a product is already discounted (e.g., special price, catalog price rules).
- Leaves other cart items eligible for discounts.
- Provides a pluggable, DI-driven architecture to decide:
  - If exclusion strategies should run (Strategy Eligibility Guards).
  - Which exclusion strategies apply to a product.
- Displays user-friendly messages explaining why coupons weren't applied to specific products.

## Architecture Overview

This module uses an **Around Plugin** on `Magento\SalesRule\Model\Validator::process()` to intercept discount validation before Magento applies cart price rules (coupons/promotions) to quote items.

### The Flow

1. **Module State Check**: Plugin checks if module is enabled for the current store view via admin configuration.
2. **Plugin Interception**: When Magento processes a sales rule for a quote item, `ValidatorPlugin::aroundProcess()` intercepts the call.
3. **Product Extraction**: The plugin identifies the actual product (handling configurable products by examining children).
4. **Guard Evaluation**: Strategy Eligibility Guards run first to determine if exclusion logic should even be considered.
5. **Strategy Evaluation**: If guards allow, Discount Exclusion Strategies check if the product already has a discount.
6. **Decision**: If excluded, the plugin returns without calling `$proceed()`, blocking the discount. Otherwise, it calls `$proceed()` to allow normal discount processing.
7. **User Feedback**: When a product is excluded, a message is displayed (with deduplication to avoid spam).

## Core Components

The module is built around two key extensible components:

### 1. Strategy Eligibility Guards

**Purpose**: Guards act as gatekeepers that determine whether discount exclusion strategies should run at all. They provide an early exit mechanism to skip expensive strategy evaluation when it doesn't make sense.

**When to use guards**:
- Filter out special rule types (e.g., free gift promotions that shouldn't be blocked)
- Skip zero-price items (no discount can apply)
- Check customer groups, store views, or date ranges
- Validate product types or item states
- Prevent strategy execution based on rule characteristics

**Interface**:
```php
namespace PixelPerfect\DiscountExclusion\Api;

interface StrategyEligibilityGuardInterface
{
    /**
     * Determines if discount exclusion strategies should be evaluated
     *
     * @param ProductInterface|Product $product The product being evaluated
     * @param AbstractItem             $item    The quote item
     * @param Rule                     $rule    The sales rule being applied
     * @return bool True if strategies should run, false to skip all strategies
     */
    public function canProcess(
        ProductInterface|Product $product,
        AbstractItem $item,
        Rule $rule
    ): bool;
}
```

**Built-in Guards**:

1. **ZeroPrice**: Skips exclusion logic for products with zero final price (no discount applicable)
   ```php
   // Returns false if price is zero, preventing unnecessary strategy checks
   return $product->getFinalPrice() > 0;
   ```

2. **Ampromo**: Skips exclusion for "Amasty Free Gift" rules (those rules provide free items, not additional discounts)
   ```php
   // Check if rule is an Ampromo free gift rule
   $simpleAction = $rule->getSimpleAction();
   if ($simpleAction && str_contains($simpleAction, 'ampromo')) {
       return false; // Don't block free gift rules
   }
   return true;
   ```

**Key Points**:
- Guards return `false` to **skip** strategy evaluation (allow the discount)
- Guards return `true` to **allow** strategy evaluation to proceed
- If **any** guard returns `false`, all strategies are skipped
- Guards have access to the full Rule object for sophisticated filtering

### 2. Discount Exclusion Strategies

**Purpose**: Strategies contain the actual business logic to determine if a product should be excluded from additional cart discounts because it already has a discount applied through another mechanism.

**When to use strategies**:
- Detect products with active special prices
- Check if catalog price rules are affecting the product
- Identify products with tier pricing
- Check for manufacturer promotions or wholesale pricing
- Any custom discount mechanism that should prevent stacking cart discounts

**Interface**:
```php
namespace PixelPerfect\DiscountExclusion\Api;

interface DiscountExclusionStrategyInterface
{
    /**
     * Determines if a product should be excluded from cart discounts
     *
     * @param ProductInterface|Product $product The product to check
     * @param AbstractItem             $item    The quote item
     * @return bool True to exclude from cart discounts, false to allow other strategies to decide
     */
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item
    ): bool;
}
```

**Built-in Strategies**:

1. **SpecialPriceStrategy**: Excludes products where the special price is active and equals the final price
   ```php
   // Checks if product has special price active and applied
   // Returns true if special price is the discount mechanism
   ```

2. **CatalogRuleStrategy**: Excludes products affected by catalog price rules
   ```php
   // Checks if catalog price rule discount is applied to product
   // Returns true if catalog rule is providing the discount
   ```

**Key Points**:
- Strategies return `true` to **exclude** the product from cart discounts
- Strategies return `false` to let other strategies decide
- Strategies are evaluated in order; **first match wins**
- Strategies only run if all guards returned `true`
- Strategies focus on "is this product already discounted?" logic

## How it works (detailed)

### Step-by-Step Process

1. **Module State Check**:
   - Plugin checks if module is enabled via `Config::isEnabled($item->getStoreId())`
   - If disabled, immediately returns `$proceed()` to allow normal discount processing
   - This provides a global kill switch without uninstalling the module

2. **Interception Point**:
   - Plugin intercepts at `Magento\SalesRule\Model\Validator::process(AbstractItem $item, Rule $rule)`
   - This gives access to both the quote item and the sales rule being applied

3. **Child Item Filtering**:
   - Skip child items of configurable products (return `$proceed()` immediately)
   - Only process parent items or simple products

4. **Product Identification**:
   ```php
   $product = $item->getProduct();
   $children = $item->getChildren();
   if (count($children) > 0 && $children[0]->getProduct()) {
       $product = $children[0]->getProduct(); // Use child for configurable pricing
   }
   ```

5. **Guard Evaluation** (via `DiscountExclusionManager`):
   - Each guard's `canProcess()` method is called sequentially
   - If **any** guard returns `false`, strategy evaluation is skipped entirely
   - The item remains eligible for the discount (return `$proceed()`)

6. **Strategy Evaluation** (if guards allow):
   - Each strategy's `shouldExcludeFromDiscount()` method is called sequentially
   - **First** strategy that returns `true` triggers exclusion
   - Remaining strategies are not evaluated (short-circuit)

7. **Exclusion Handling**:
   - If excluded and not already processed:
     - Display error message: "Coupon X was not applied to Product Y because it is already discounted"
     - Mark product as processed in session (prevents duplicate messages)
   - Return `$subject` without calling `$proceed()` (blocks discount application)

8. **Allow Handling**:
   - If not excluded, call `$proceed($item, $rule)` to allow normal discount processing

## Extending the Module

The module is designed to be extended through dependency injection. You can add your own guards and strategies without modifying core module code.

### Adding a Strategy Eligibility Guard

**Step 1**: Create your guard class implementing `StrategyEligibilityGuardInterface`

```php
<?php declare(strict_types=1);

namespace YourVendor\YourModule\Model\StrategyEligibilityGuards;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountExclusion\Api\StrategyEligibilityGuardInterface;

/**
 * Example: Skip exclusion for specific customer groups
 */
class CustomerGroupGuard implements StrategyEligibilityGuardInterface
{
    public function __construct(
        private readonly \Magento\Customer\Model\Session $customerSession
    ) {
    }

    public function canProcess(
        ProductInterface|Product $product,
        AbstractItem $item,
        Rule $rule
    ): bool {
        // Don't apply exclusion logic for VIP customers (group ID 4)
        $customerGroupId = $this->customerSession->getCustomerGroupId();

        if ($customerGroupId === 4) {
            return false; // Skip strategies, allow discount
        }

        return true; // Allow strategies to evaluate
    }
}
```

**Step 2**: Register your guard in `etc/di.xml`

```xml
<type name="PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager">
    <arguments>
        <argument name="strategyEligibilityGuards" xsi:type="array">
            <!-- Built-in guards -->
            <item name="ampromo" xsi:type="object">PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\Ampromo</item>
            <item name="zero_price" xsi:type="object">PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\ZeroPrice</item>

            <!-- Your custom guard -->
            <item name="customer_group" xsi:type="object">YourVendor\YourModule\Model\StrategyEligibilityGuards\CustomerGroupGuard</item>
        </argument>
    </arguments>
</type>
```

**Guard Best Practices**:
- **Order matters**: Place cheap, frequently-triggered guards first for better performance
- **Return `false` to allow discounts**: When a guard returns `false`, strategies are skipped and the discount is allowed
- **Return `true` to continue**: When all guards return `true`, strategies will evaluate
- **Keep it lightweight**: Guards run on every item/rule combination, so avoid expensive operations
- **Use Rule object**: Access rule properties like `$rule->getSimpleAction()`, `$rule->getRuleId()`, `$rule->getStoreLabels()`, etc.

**Common Guard Use Cases**:
```php
// Skip specific rule types
if ($rule->getSimpleAction() === 'by_percent' && $rule->getDiscountAmount() == 100) {
    return false; // 100% off rules (free items) - don't block
}

// Date/time conditions
$now = new \DateTime();
$blackFriday = new \DateTime('2024-11-29');
if ($now >= $blackFriday) {
    return false; // Allow stacking during Black Friday
}

// Product attribute checks
if ($product->getAttributeSetId() === 10) {
    return false; // Digital products can stack discounts
}
```

### Adding a Discount Exclusion Strategy

**Step 1**: Create your strategy class implementing `DiscountExclusionStrategyInterface`

```php
<?php declare(strict_types=1);

namespace YourVendor\YourModule\Model\Strategy;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use PixelPerfect\DiscountExclusion\Api\DiscountExclusionStrategyInterface;

/**
 * Example: Exclude products with tier pricing
 */
class TierPriceStrategy implements DiscountExclusionStrategyInterface
{
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item
    ): bool {
        // Check if product has tier pricing configured
        $tierPrices = $product->getTierPrice();

        if (!empty($tierPrices)) {
            // Check if tier pricing is actually applying
            $regularPrice = $product->getPrice();
            $finalPrice = $product->getFinalPrice();

            if ($finalPrice < $regularPrice) {
                return true; // Exclude: tier pricing is active
            }
        }

        return false; // Don't exclude based on tier pricing
    }
}
```

**Step 2**: Register your strategy in `etc/di.xml`

```xml
<type name="PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager">
    <arguments>
        <argument name="strategies" xsi:type="array">
            <!-- Built-in strategies -->
            <item name="special_price" xsi:type="object">PixelPerfect\DiscountExclusion\Model\Strategy\SpecialPriceStrategy</item>
            <item name="catalog_rule" xsi:type="object">PixelPerfect\DiscountExclusion\Model\Strategy\CatalogRuleStrategy</item>

            <!-- Your custom strategy -->
            <item name="tier_price" xsi:type="object">YourVendor\YourModule\Model\Strategy\TierPriceStrategy</item>
        </argument>
    </arguments>
</type>
```

**Strategy Best Practices**:
- **Return `true` to exclude**: Product will be blocked from receiving the cart discount
- **Return `false` to continue**: Let other strategies decide; don't influence the decision
- **First match wins**: Strategies are evaluated in order; once one returns `true`, evaluation stops
- **Order strategically**: Place most common exclusion reasons first for performance
- **Focus on "already discounted"**: Strategies should answer "is this product already discounted by X mechanism?"

**Common Strategy Examples**:
```php
// Check for manufacturer promotions via custom attribute
public function shouldExcludeFromDiscount(
    ProductInterface|Product $product,
    AbstractItem $item
): bool {
    $hasManufacturerPromo = $product->getData('manufacturer_promo_active');
    return (bool)$hasManufacturerPromo;
}

// Exclude products with custom price adjustments
public function shouldExcludeFromDiscount(
    ProductInterface|Product $product,
    AbstractItem $item
): bool {
    $customPrice = $item->getCustomPrice();
    return $customPrice !== null; // Has custom price set
}

// Exclude clearance items
public function shouldExcludeFromDiscount(
    ProductInterface|Product $product,
    AbstractItem $item
): bool {
    $isClearance = $product->getAttributeText('is_clearance');
    return $isClearance === 'Yes';
}
```

## Configuration

The module provides both admin panel configuration and dependency injection configuration.

### Admin Configuration

Navigate to `Stores → Configuration → Sales → Discount Exclusion` to access module settings.

**Available Settings:**

| Setting | Scope | Default | Description |
|---------|-------|---------|-------------|
| Enable Module | Store View | Yes | Enables or disables the discount exclusion functionality |

**Configuration Details:**
- **Scope**: Store-view level (can be configured per store)
- **Config Path**: `discount_exclusion/general/enabled`
- **ACL Permission**: `PixelPerfect_DiscountExclusion::config`
- **Behavior**: When disabled, the plugin immediately allows all discounts to proceed without evaluation
- **Cache**: Uses Magento's system configuration cache

**Programmatic Access:**
```php
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;

public function __construct(
    private readonly ConfigInterface $config
) {
}

public function someMethod(int $storeId): void
{
    if ($this->config->isEnabled($storeId)) {
        // Module is enabled for this store
    }
}
```

### Dependency Injection Configuration

Extension points (guards and strategies) are configured via **dependency injection** (`etc/di.xml`).

### Complete di.xml Example

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">

    <!-- Configure guards and strategies -->
    <type name="PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager">
        <arguments>
            <!-- Strategy Eligibility Guards (run first, gate-keeping) -->
            <argument name="strategyEligibilityGuards" xsi:type="array">
                <item name="ampromo" xsi:type="object">
                    PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\Ampromo
                </item>
                <item name="zero_price" xsi:type="object">
                    PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\ZeroPrice
                </item>
                <!-- Add your custom guards here -->
            </argument>

            <!-- Discount Exclusion Strategies (run if guards allow) -->
            <argument name="strategies" xsi:type="array">
                <item name="special_price" xsi:type="object">
                    PixelPerfect\DiscountExclusion\Model\Strategy\SpecialPriceStrategy
                </item>
                <item name="catalog_rule" xsi:type="object">
                    PixelPerfect\DiscountExclusion\Model\Strategy\CatalogRuleStrategy
                </item>
                <!-- Add your custom strategies here -->
            </argument>
        </arguments>
    </type>

</config>
```

### Ordering Considerations

**Guards** - Order from most restrictive/cheapest to least:
1. Zero-price check (very cheap)
2. Rule type checks (cheap, using Rule object)
3. Customer/session checks (moderate)
4. Database lookups (more expensive)

**Strategies** - Order from most common to least common:
1. Special price (very common)
2. Catalog rules (common)
3. Tier pricing (less common)
4. Custom mechanisms (rare)

## Technical Details

### File Structure

```
src/
├── Api/
│   ├── ConfigInterface.php                      # Configuration service interface
│   ├── DiscountExclusionManagerInterface.php    # Main manager interface
│   ├── DiscountExclusionStrategyInterface.php   # Strategy interface
│   ├── StrategyEligibilityGuardInterface.php    # Guard interface
│   └── MessageProcessorInterface.php            # Message handling
├── Plugin/
│   └── SalesRule/
│       └── Model/
│           └── ValidatorPlugin.php              # Main interception point
├── Model/
│   ├── Strategy/
│   │   ├── SpecialPriceStrategy.php            # Built-in strategy
│   │   └── CatalogRuleStrategy.php             # Built-in strategy
│   ├── StrategyEligibilityGuards/
│   │   ├── Ampromo.php                         # Built-in guard
│   │   └── ZeroPrice.php                       # Built-in guard
│   ├── MessageGroups.php                        # Message group constants
│   └── SessionKeys.php                          # Session key constants
├── Service/
│   ├── Config.php                               # Configuration service implementation
│   ├── DiscountExclusionManager.php            # Main business logic
│   └── MessageProcessor.php                     # Message deduplication
├── etc/
│   ├── acl.xml                                  # Admin ACL permissions
│   ├── config.xml                               # Default configuration values
│   ├── di.xml                                   # Dependency injection config
│   └── adminhtml/
│       └── system.xml                           # Admin configuration fields
└── i18n/
    ├── en_US.csv                                # English translations
    ├── de_DE.csv                                # German translations
    ├── it_IT.csv                                # Italian translations
    ├── fr_FR.csv                                # French translations
    └── es_ES.csv                                # Spanish translations
```

### Key Classes

**Config** (`src/Service/Config.php`):
- Implements `ConfigInterface`
- Reads configuration from `ScopeConfigInterface`
- Checks if module is enabled at store-view level
- Uses config path: `discount_exclusion/general/enabled`

**ValidatorPlugin** (`src/Plugin/SalesRule/Model/ValidatorPlugin.php`):
- Intercepts `Magento\SalesRule\Model\Validator::process()`
- Checks module enabled state before processing
- Provides access to both quote item and sales rule
- Delegates decision-making to `DiscountExclusionManager`
- Handles message display and session-based deduplication

**DiscountExclusionManager** (`src/Service/DiscountExclusionManager.php`):
- Orchestrates guard and strategy evaluation
- Short-circuits on first guard that returns `false`
- Short-circuits on first strategy that returns `true`
- Pure business logic, no side effects

**Message Handling**:
- Messages are grouped by `MessageGroups::DISCOUNT_EXCLUSION`
- Session tracks processed product IDs via `SessionKeys::PROCESSED_PRODUCT_IDS`
- Prevents duplicate messages for the same product in a single checkout session

### Plugin Details

The plugin is registered in `etc/di.xml`:
```xml
<type name="Magento\SalesRule\Model\Validator">
    <plugin name="discount_exclusion_validator"
            type="PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\ValidatorPlugin"
            sortOrder="10"/>
</type>
```

**Plugin Method Signature**:
```php
public function aroundProcess(
    Validator $subject,
    callable $proceed,
    AbstractItem $item,
    Rule $rule
): Validator
```

**Return Values**:
- Returns `$subject` without calling `$proceed()` → Blocks discount application
- Returns `$proceed($item, $rule)` → Allows normal discount processing

## Requirements

- Magento 2.4.x or higher
- PHP 8.2+
- Modern Magento coding standards (constructor property promotion, typed properties)

## Installation

```bash
composer require pixelperfectat/magento2-module-discount-exclusion
bin/magento module:enable PixelPerfect_DiscountExclusion
bin/magento setup:upgrade
bin/magento cache:flush
```

## Debugging & Troubleshooting

### Enable Xdebug Breakpoints

Key breakpoints for debugging:
- `ValidatorPlugin::aroundProcess()` - Entry point for discount validation
- `DiscountExclusionManager::shouldExcludeFromDiscount()` - Decision logic
- Your custom guard/strategy classes - Verify they're being called

### Common Issues

**Discounts not being blocked**:
1. Check if a guard is returning `false` (allowing discount)
2. Verify strategies are returning `true` when they should exclude
3. Confirm your classes are registered in `di.xml`
4. Run `bin/magento cache:clean` after di.xml changes

**Messages not displaying**:
1. Check session - messages are deduplicated per product
2. Verify coupon code is available (required for message display)
3. Clear checkout session to reset processed product IDs

**Performance concerns**:
1. Optimize guard order (cheap checks first)
2. Avoid heavy database queries in guards
3. Use caching within strategies if needed
4. Consider using proxies for expensive dependencies

## Roadmap

- Additional default guards and strategies
- Event dispatching for observability
- Admin configuration options (optional)
- Performance monitoring and metrics
- Compatibility with third-party discount modules

## Contributing

This module follows Magento coding standards and PHP 8.2+ syntax. When contributing:
- Use strict types (`declare(strict_types=1)`)
- Use constructor property promotion
- Follow PSR-12 coding style
- Add comprehensive PHPDoc blocks
- Include unit tests for new functionality

## License

See LICENSE.md for license details.

## Support

This module is under active development. APIs and behavior may change without backward compatibility guarantees until version 1.0.0.

For issues, feature requests, or questions, please open an issue in the repository.
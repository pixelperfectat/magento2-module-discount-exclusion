# PixelPerfect Discount Exclusion

Extensible Magento 2 module that prevents applying shopping cart (sales rule) discounts to products already discounted by other mechanisms.

Status: Active development. APIs and behavior may change without backward compatibility.

## What it does

- Blocks additional cart discounts when a product is already discounted (e.g., special price, catalog price rules).
- Leaves other cart items eligible for discounts.
- Provides a pluggable, DI-driven architecture to decide:
  - If exclusion strategies should run (Strategy Eligibility Guards).
  - Which exclusion strategies apply to a product.
- **Per-rule bypass toggle** with max-discount logic: when enabled on a rule, the customer receives `max(existing discount, rule discount)` calculated from the regular price, instead of stacking discounts.
- Displays user-friendly messages explaining why coupons weren't applied or were adjusted for specific products.
- Queues messages in the session and displays them only on the cart page for a cleaner UX.
- Automatically removes coupon from cart when no actual discount was applied.

## Architecture Overview

This module uses an **Around Plugin** on `Magento\SalesRule\Model\Validator::process()` to intercept discount validation before Magento applies cart price rules (coupons/promotions) to quote items.

### The Flow

1. **Module State Check**: Plugin checks if module is enabled for the current store view via admin configuration.
2. **Plugin Interception**: When Magento processes a sales rule for a quote item, `ValidatorPlugin::aroundProcess()` intercepts the call.
3. **Product Extraction**: The plugin identifies the actual product (handling configurable products by examining children).
4. **Bypass Check**: If the rule has `bypass_discount_exclusion` enabled, the bypass flow runs instead of the standard exclusion flow.
5. **Guard Evaluation**: Strategy Eligibility Guards run first to determine if exclusion logic should be considered.
6. **Strategy Evaluation**: If guards allow, Discount Exclusion Strategies check if the product already has a discount.
7. **Decision**:
   - **Standard flow**: If excluded, the plugin returns without calling `$proceed()`, blocking the discount. Otherwise, it calls `$proceed()`.
   - **Bypass flow**: `MaxDiscountCalculator` computes the result. The discount may be adjusted (capped to the difference), blocked (existing is better), or allowed fully (stacking fallback for unsupported rule types).
8. **Result Collection**: Excluded and bypassed items are collected by `ExclusionResultCollector` during processing.
9. **User Feedback**: Messages are queued in the session and displayed on the cart page via `CartPageLoadObserver`. On coupon apply, `CouponPostObserver` displays messages immediately.

### Bypass Flow (Max-Discount Logic)

When a cart rule has the **Bypass Discount Exclusion** toggle enabled, the plugin applies maximum discount logic instead of blocking the discount entirely:

```
ValidatorPlugin::aroundProcess()
├── Rule has bypass_discount_exclusion?
│   ├── Product NOT already discounted → proceed() (normal discount)
│   └── Product IS already discounted → MaxDiscountCalculator
│       ├── STACKING_FALLBACK (cart_fixed/buy_x_get_y) → proceed()
│       ├── EXISTING_BETTER (existing >= rule) → block + message
│       └── ADJUSTED (rule > existing) → proceed() then cap discount + message
└── No bypass → standard exclusion flow
```

**Example:** Product regular price 100, special price 75 (25% off). Coupon is 30% off.
- **Without bypass**: Coupon blocked entirely (product already discounted).
- **With bypass**: `max(25%, 30%) = 30%` → target price 70 → additional discount of 5 applied → customer pays 70.

## Core Components

### 1. Strategy Eligibility Guards

**Purpose**: Guards act as gatekeepers that determine whether discount exclusion strategies should run at all. They provide an early exit mechanism to skip expensive strategy evaluation when it doesn't make sense.

**When to use guards**:
- Filter out special rule types (e.g., free gift promotions that shouldn't be blocked)
- Skip zero-price items (no discount can apply)
- Only apply to coupon-based rules (skip automatic promotions)
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

1. **CouponOnly**: Only applies exclusion logic to coupon-based rules. Automatic cart rules (no coupon required) proceed normally without exclusion checks.
   ```php
   // Returns false for automatic rules (COUPON_TYPE_NO_COUPON)
   // Returns true for specific coupon or auto-generated coupon rules
   return $couponType !== Rule::COUPON_TYPE_NO_COUPON;
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

3. **ZeroPrice**: Skips exclusion logic for products with zero final price (no discount applicable)
   ```php
   // Returns false if price is zero, preventing unnecessary strategy checks
   return $product->getFinalPrice() > 0;
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
2. **CatalogRuleStrategy**: Excludes products affected by catalog price rules

**Key Points**:
- Strategies return `true` to **exclude** the product from cart discounts
- Strategies return `false` to let other strategies decide
- Strategies are evaluated in order; **first match wins**
- Strategies only run if all guards returned `true`
- Strategies focus on "is this product already discounted?" logic

### 3. Max Discount Calculator

**Purpose**: Computes the capped discount when a bypassed rule applies to an already-discounted product. The customer receives `max(existing discount, rule discount)` from the regular price.

**Interface**:
```php
namespace PixelPerfect\DiscountExclusion\Api;

interface MaxDiscountCalculatorInterface
{
    /**
     * Calculate the max-discount result for a bypassed rule
     *
     * @param ProductInterface|Product $product The product (with prices loaded)
     * @param Rule                     $rule    The cart price rule being evaluated
     * @param float                    $qty     Item quantity in the cart
     * @return BypassResult
     */
    public function calculate(ProductInterface|Product $product, Rule $rule, float $qty): BypassResult;
}
```

**Supported rule types**:
- `by_percent` — percentage-based discount
- `by_fixed` — fixed amount discount
- `cart_fixed` / `buy_x_get_y` — returns `STACKING_FALLBACK` (full stacking, max-discount not applicable)

**Result types** (`BypassResultType` enum):
- `ADJUSTED` — Rule discount exceeds existing; apply only the difference
- `EXISTING_BETTER` — Existing discount is equal or greater; block the rule discount
- `STACKING_FALLBACK` — Rule type not supported for max-discount; allow full stacking

### 4. Exclusion Result Collector

**Purpose**: A singleton service that collects excluded and bypassed items during quote processing, enabling consolidated message display after all items are processed.

**Interface**:
```php
namespace PixelPerfect\DiscountExclusion\Api;

interface ExclusionResultCollectorInterface
{
    // Exclusion tracking
    public function addExcludedItem(AbstractItem $item, string $reason, string $couponCode): void;
    public function hasExcludedItems(string $couponCode): bool;
    public function hasAnyExcludedItems(): bool;
    public function getExcludedItems(string $couponCode): array;
    public function getCouponCodes(): array;

    // Bypass tracking
    public function addBypassedItem(AbstractItem $item, BypassResultType $type, string $couponCode, array $messageParams = []): void;
    public function hasBypassedItems(string $couponCode): bool;
    public function hasAnyBypassedItems(): bool;
    public function getBypassedItems(string $couponCode): array;

    public function clear(): void;
}
```

**Key Points**:
- Collects excluded and bypassed items per coupon code
- Deduplicates by product ID (same product won't be added twice)
- Bypass items carry message parameters for rendering (discount percentages, amounts)
- Cleared after messages are displayed

### 5. Exclusion Message Builder

**Purpose**: Builds consolidated exclusion and bypass messages for a given coupon code. Supports both immediate display (via message manager) and deferred display (via session queuing).

**Interface**:
```php
namespace PixelPerfect\DiscountExclusion\Api;

interface ExclusionMessageBuilderInterface
{
    // Add messages directly to the message manager
    public function addMessagesForCoupon(string $couponCode): void;

    // Build messages for session queuing (returns array of {type, text})
    public function buildMessagesForCoupon(string $couponCode): array;
}
```

**Message types**:
- **Exclusion warnings**: "Coupon X was not applied to Y because it is already discounted"
- **Bypass adjusted notices**: "Coupon X applied an additional 5% discount to Y, adjusted from 30% because it is already 25% discounted"
- **Bypass existing_better warnings**: "Coupon X was not applied to Y because the existing 25% discount already exceeds the coupon's 20% discount"

### 6. Observers

**CouponPostObserver**: Handles message display and coupon cleanup after coupon application
- Listens to `controller_action_postdispatch_checkout_cart_couponPost`
- Displays consolidated messages for all excluded and bypassed products
- Removes coupon from quote if no actual discount was applied
- Clears Magento's generic error messages and replaces with specific exclusion messages

**CartUpdateObserver**: Queues messages for display on the cart page
- Listens to cart add, update, and delete post-dispatch events
- Builds messages via `ExclusionMessageBuilder` and stores them in the checkout session
- Messages are only displayed when the cart page loads (not on PLP/PDP)

**CartPageLoadObserver**: Displays queued messages and clears session state on cart page load
- Listens to `controller_action_predispatch_checkout_cart_index`
- Reads queued messages from session and displays them
- Clears processed product IDs from session

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
            <item name="coupon_only" xsi:type="object">PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\CouponOnly</item>
            <item name="ampromo" xsi:type="object">PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\Ampromo</item>
            <item name="zero_price" xsi:type="object">PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\ZeroPrice</item>

            <!-- Your custom guard -->
            <item name="customer_group" xsi:type="object">YourVendor\YourModule\Model\StrategyEligibilityGuards\CustomerGroupGuard</item>
        </argument>
    </arguments>
</type>
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

class TierPriceStrategy implements DiscountExclusionStrategyInterface
{
    public function shouldExcludeFromDiscount(
        ProductInterface|Product $product,
        AbstractItem $item
    ): bool {
        $tierPrices = $product->getTierPrice();

        if (!empty($tierPrices)) {
            $regularPrice = $product->getPrice();
            $finalPrice = $product->getFinalPrice();

            if ($finalPrice < $regularPrice) {
                return true; // Exclude: tier pricing is active
            }
        }

        return false;
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

## Configuration

### Admin Configuration

Navigate to `Stores > Configuration > Sales > Discount Exclusion` to access module settings.

| Setting | Scope | Default | Description |
|---------|-------|---------|-------------|
| Enable Module | Store View | Yes | Enables or disables the discount exclusion functionality |

**Config Path**: `discount_exclusion/general/enabled`
**ACL Permission**: `PixelPerfect_DiscountExclusion::config`

### Per-Rule Bypass Toggle

Each cart price rule has a **Bypass Discount Exclusion** toggle on the Rule Information tab.

When enabled, the rule uses maximum discount logic instead of being blocked:
- The customer receives `max(existing discount, rule discount)` calculated from the regular price
- Only the difference is applied as an additional cart-rule discount
- Supported for `by_percent` and `by_fixed` rule types
- `cart_fixed` and `buy_x_get_y` rules fall back to full stacking

The toggle is stored as the `bypass_discount_exclusion` column on the `salesrule` table.

## Technical Details

### File Structure

```
src/
├── Api/
│   ├── ConfigInterface.php
│   ├── Data/
│   │   ├── BypassResult.php                       # Readonly value object for max-discount results
│   │   └── BypassResultType.php                   # Enum: ADJUSTED, EXISTING_BETTER, STACKING_FALLBACK
│   ├── DiscountExclusionManagerInterface.php
│   ├── DiscountExclusionStrategyInterface.php
│   ├── ExclusionMessageBuilderInterface.php       # Message building service interface
│   ├── ExclusionResultCollectorInterface.php
│   ├── MaxDiscountCalculatorInterface.php         # Max-discount calculator interface
│   ├── MessageProcessorInterface.php
│   └── StrategyEligibilityGuardInterface.php
├── Model/
│   ├── MessageGroups.php
│   ├── SessionKeys.php
│   ├── Strategy/
│   │   ├── CatalogRuleStrategy.php
│   │   └── SpecialPriceStrategy.php
│   └── StrategyEligibilityGuards/
│       ├── Ampromo.php
│       ├── CouponOnly.php
│       └── ZeroPrice.php
├── Observer/
│   ├── CartPageLoadObserver.php                   # Displays queued messages on cart page
│   ├── CartUpdateObserver.php                     # Queues messages on cart changes
│   └── CouponPostObserver.php                     # Handles coupon apply messages + removal
├── Plugin/
│   └── SalesRule/
│       └── Model/
│           └── ValidatorPlugin.php                # Main interception point + bypass logic
├── Service/
│   ├── Config.php
│   ├── DiscountExclusionManager.php
│   ├── ExclusionMessageBuilder.php                # Builds exclusion + bypass messages
│   ├── ExclusionResultCollector.php               # Collects excluded + bypassed items
│   ├── MaxDiscountCalculator.php                  # Computes capped bypass discounts
│   └── MessageProcessor.php
├── etc/
│   ├── acl.xml
│   ├── adminhtml/
│   │   └── system.xml
│   ├── config.xml
│   ├── db_schema.xml                              # bypass_discount_exclusion column
│   ├── db_schema_whitelist.json
│   ├── di.xml
│   ├── frontend/
│   │   └── events.xml
│   └── module.xml
├── i18n/
│   ├── de_DE.csv
│   ├── en_US.csv
│   ├── es_ES.csv
│   ├── fr_FR.csv
│   └── it_IT.csv
├── registration.php
└── view/
    └── adminhtml/
        └── ui_component/
            └── sales_rule_form.xml                # Bypass toggle on rule form

Test/
└── Unit/
    ├── Model/
    │   ├── Strategy/
    │   │   ├── CatalogRuleStrategyTest.php
    │   │   └── SpecialPriceStrategyTest.php
    │   └── StrategyEligibilityGuards/
    │       ├── AmpromoTest.php
    │       ├── CouponOnlyTest.php
    │       └── ZeroPriceTest.php
    ├── Observer/
    │   ├── CartPageLoadObserverTest.php
    │   └── CartUpdateObserverTest.php
    ├── Plugin/
    │   └── SalesRule/
    │       └── Model/
    │           └── ValidatorPluginTest.php
    └── Service/
        ├── DiscountExclusionManagerTest.php
        ├── ExclusionMessageBuilderTest.php
        ├── ExclusionResultCollectorTest.php
        └── MaxDiscountCalculatorTest.php
```

### Event Observers

```xml
<!-- Cart page load: display queued messages, clear session -->
<event name="controller_action_predispatch_checkout_cart_index">
    <observer name="discount_exclusion_cart_load"
              instance="PixelPerfect\DiscountExclusion\Observer\CartPageLoadObserver"/>
</event>

<!-- Coupon apply: display messages immediately, remove coupon if needed -->
<event name="controller_action_postdispatch_checkout_cart_couponPost">
    <observer name="discount_exclusion_coupon_post"
              instance="PixelPerfect\DiscountExclusion\Observer\CouponPostObserver"/>
</event>

<!-- Cart changes: queue messages for cart page display -->
<event name="controller_action_postdispatch_checkout_cart_add">
    <observer name="discount_exclusion_cart_add"
              instance="PixelPerfect\DiscountExclusion\Observer\CartUpdateObserver"/>
</event>
<event name="controller_action_postdispatch_checkout_cart_updatePost">
    <observer name="discount_exclusion_cart_update"
              instance="PixelPerfect\DiscountExclusion\Observer\CartUpdateObserver"/>
</event>
<event name="controller_action_postdispatch_checkout_cart_delete">
    <observer name="discount_exclusion_cart_delete"
              instance="PixelPerfect\DiscountExclusion\Observer\CartUpdateObserver"/>
</event>
```

## Requirements

- Magento 2.4.x or higher
- PHP 8.2+

## Installation

```bash
composer require pixelperfectat/magento2-module-discount-exclusion
bin/magento module:enable PixelPerfect_DiscountExclusion
bin/magento setup:upgrade
bin/magento cache:flush
```

## Testing

The module includes comprehensive unit tests for all core components.

```bash
# Run all module tests
vendor/bin/phpunit

# Run specific test class
vendor/bin/phpunit Test/Unit/Service/MaxDiscountCalculatorTest.php
```

**Static analysis** (PHPStan level 6):
```bash
vendor/bin/phpstan analyse
```

**Test coverage**:
- Guards: `Ampromo`, `CouponOnly`, `ZeroPrice`
- Strategies: `SpecialPriceStrategy`, `CatalogRuleStrategy`
- Services: `DiscountExclusionManager`, `ExclusionResultCollector`, `MaxDiscountCalculator`, `ExclusionMessageBuilder`
- Observers: `CartPageLoadObserver`, `CartUpdateObserver`
- Plugins: `ValidatorPlugin` (standard exclusion + bypass flows)

## Translations

Supported locales: `en_US`, `de_DE`, `it_IT`, `fr_FR`, `es_ES`

All exclusion messages, bypass messages, admin labels, and tooltips are translatable.

## Debugging

The module logs to `var/log/debug.log` with the `DiscountExclusion:` prefix. Key breakpoints for debugging:

- `ValidatorPlugin::aroundProcess()` — Entry point for discount validation
- `ValidatorPlugin::handleBypass()` — Bypass flow with max-discount logic
- `DiscountExclusionManager::shouldExcludeFromDiscount()` — Guard and strategy evaluation
- `MaxDiscountCalculator::calculate()` — Max-discount computation
- `CouponPostObserver::execute()` — Coupon apply message display
- `CartUpdateObserver::execute()` — Cart change message queuing

## Roadmap

- Additional default guards and strategies
- Event dispatching for observability
- Performance monitoring and metrics
- Compatibility with third-party discount modules
- GraphQL support for headless checkout

## License

See LICENSE.md for license details.

## Support

This module is under active development. APIs and behavior may change without backward compatibility guarantees until version 1.0.0.

For issues, feature requests, or questions, please [open an issue](https://github.com/pixelperfectat/magento2-module-discount-exclusion/issues).

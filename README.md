# PixelPerfect Discount Exclusion

[![CI](https://github.com/pixelperfectat/magento2-module-discount-exclusion/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/pixelperfectat/magento2-module-discount-exclusion/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/pixelperfectat/magento2-module-discount-exclusion)](https://packagist.org/packages/pixelperfectat/magento2-module-discount-exclusion)
[![PHP](https://img.shields.io/packagist/dependency-v/pixelperfectat/magento2-module-discount-exclusion/php)](composer.json)
[![License: MIT](https://img.shields.io/packagist/l/pixelperfectat/magento2-module-discount-exclusion)](LICENSE)

Extensible Magento 2 module that prevents applying shopping cart (sales rule) discounts to products already discounted by other mechanisms.

Status: Stable. Follows [semantic versioning](https://semver.org/) from 1.0.0.

## What it does

- Blocks additional cart discounts when a product is already discounted (e.g., special price, catalog price rules).
- Leaves other cart items eligible for discounts.
- Provides a pluggable, DI-driven architecture to decide:
  - If exclusion strategies should run (Strategy Eligibility Guards).
  - Which exclusion strategies apply to a product.
- **Per-rule bypass toggle** with max-discount logic: when enabled on a rule, the customer receives `max(existing discount, rule discount)` calculated from the regular price, instead of stacking discounts.
- Displays user-friendly messages explaining why coupons weren't applied or were adjusted for specific products (can be turned off with **Show Customer Messages**).
- Queues messages in the session and displays them only on the cart page for a cleaner UX.
- Removes the coupon from the cart when it was applied through the cart page coupon form (`checkout/cart/couponPost`) and no actual discount resulted. See [Known limitations](#known-limitations).
- Exposes the per-rule bypass toggle as a REST extension attribute and ships a CLI command to apply the configured default to existing rules.

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
10. **Item markers**: The plugin also records its decision on the quote item as data (`pp_discount_excluded`, `pp_discount_exclusion_reason` = `standard` or `existing_better`, `pp_discount_bypass_adjusted`, `pp_discount_exclusion_params`) for other code to read. They are not persisted.

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

The cap is kept at four decimals (for example `0.9985`) and is not rounded to the currency's precision by the module; see [Known limitations](#known-limitations) for the multi-currency case.

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
    public function hasAnyExcludedItems(): bool; // deprecated, removal in 2.0.0
    public function getExcludedItems(string $couponCode): array;
    public function getCouponCodes(): array;     // deprecated, removal in 2.0.0

    // Bypass tracking
    public function addBypassedItem(AbstractItem $item, BypassResultType $type, string $couponCode, array $messageParams = []): void;
    public function hasBypassedItems(string $couponCode): bool;
    public function hasAnyBypassedItems(): bool; // deprecated, removal in 2.0.0
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
- Listens to `controller_action_postdispatch_checkout_cart_couponPost` (frontend area only)
- Removes the coupon from the quote when at least one item was excluded or bypassed for it, the shipping address discount is below 0.01, and no bypassed item was adjusted
- When **Show Customer Messages** is on, clears the messages already queued in the message manager (including Magento's generic coupon message) and adds the specific exclusion messages

**CartUpdateObserver**: Queues messages for display on the cart page
- Listens to cart add, update, and delete post-dispatch events
- Builds messages via `ExclusionMessageBuilder` and stores them in the checkout session
- Messages are only displayed when the cart page loads (not on PLP/PDP)

**CartPageLoadObserver**: Displays queued messages on cart page load
- Listens to `controller_action_predispatch_checkout_cart_index`
- Reads queued messages from session and displays them

**CollectTotalsMessageQueueObserver**: Queues messages during GraphQL requests
- Listens to `sales_quote_collect_totals_after` in the `graphql` area only
- GraphQL mutations do not dispatch controller events, so this observer builds the messages and queues them in the checkout session, where `CartPageLoadObserver` shows them on the next cart page load. GraphQL responses do not contain the messages.

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

Navigate to `Stores > Configuration > Sales > Discount Exclusion > General`. Access is controlled by the ACL resource `PixelPerfect_DiscountExclusion::config`.

| Setting | Config path | Scope | Default | Description |
|---------|-------------|-------|---------|-------------|
| Enable Module | `discount_exclusion/general/enabled` | Default, Website, Store View | Yes | Turns the exclusion and bypass logic on or off. Read per store of the quote item. |
| Show Customer Messages | `discount_exclusion/general/show_messages` | Default, Website, Store View | Yes | Shows the flash messages that explain why a coupon was not applied or was adjusted. When off, Magento's own coupon messages are left as they are. |
| Bypass Default for New Rules | `discount_exclusion/general/bypass_default` | Default only | No | Default state of the **Bypass Discount Exclusion** toggle when a new cart price rule is created in the admin. It does not change existing rules; use the CLI command below for that. |

### Per-Rule Bypass Toggle

Each cart price rule has a **Bypass Discount Exclusion** toggle on the Rule Information tab.

When enabled, the rule uses maximum discount logic instead of being blocked:
- The customer receives `max(existing discount, rule discount)` calculated from the regular price
- Only the difference is applied as an additional cart-rule discount
- Supported for `by_percent` and `by_fixed` rule types
- `cart_fixed` and `buy_x_get_y` rules fall back to full stacking

The toggle is stored as the `bypass_discount_exclusion` column (`smallint`, default `0`) on the `salesrule` table.

### REST API

The toggle is available as the extension attribute `bypass_discount_exclusion` (boolean) on `Magento\SalesRule\Api\Data\RuleInterface`, so the standard sales rule endpoints read and write it:

```json
PUT /V1/salesRules/{ruleId}
{
  "rule": {
    "extension_attributes": {
      "bypass_discount_exclusion": true
    }
  }
}
```

### CLI

```bash
# Show how many cart price rules differ from the configured "Bypass Default for New Rules"
bin/magento discount-exclusion:sync-bypass-default --dry-run

# Set bypass_discount_exclusion on every cart price rule to the configured default
bin/magento discount-exclusion:sync-bypass-default
```

The command updates the `bypass_discount_exclusion` column of every row in the `salesrule` table directly, without loading the rule models.

## Known limitations

These are known and tracked; they are not fixed in the current release.

- **Base vs. store currency** ([#6](https://github.com/pixelperfectat/magento2-module-discount-exclusion/issues/6)). The bypass cap is calculated from the product's base-currency prices and applied unchanged to both `base_discount_amount` and `discount_amount`. On a store whose display currency differs from its base currency, the display-currency discount is therefore wrong, and fixed-amount messages format a base amount with the display currency symbol.
- **Customer group and website for catalog price rules.** `CatalogRuleStrategy` takes the customer group from the customer session and the website from the current store, not from the quote. Requests without a customer session (REST and GraphQL with a customer token) are evaluated as NOT LOGGED IN, and admin-created orders are evaluated against the admin website. Catalog rules limited to a group or website can therefore be missed in those contexts.
- **Tier prices and custom prices are ignored.** The existing discount is taken from the product's regular and final price at quantity 1. Tier prices and custom item prices are not considered, so under bypass the combined discount can exceed `max(existing, rule)`.
- **Bundle products.** For items with children, the first child's product decides whether the item is excluded and is used for the cap. For dynamic-price bundles Magento distributes the discount to the children, so the cap on the parent has no effect while the item is still marked and reported as adjusted.
- **Coupon auto-removal.** It only runs on the cart page coupon form (`checkout/cart/couponPost`). Coupons applied via REST, GraphQL or checkouts with their own endpoints are not removed. It reads only the shipping address discount, which is 0 for virtual-only carts, and that amount also includes automatic (non-coupon) rule discounts.
- **Two bypass rules on one item stack.** Each bypass-enabled rule compares only against the catalog/special-price discount and ignores cart-rule discounts already on the item, so two bypass rules on the same item each add their own difference.
- The cap does not update Magento's per-rule discount breakdown (`discounts` extension attribute) or `original_discount_amount`; those still show the uncapped rule amount.

## Technical Details

### File Structure

```
src/
├── Api/                    Service contracts, BypassResult value object and BypassResultType enum
├── Console/Command/        discount-exclusion:sync-bypass-default
├── Model/
│   ├── Strategy/           SpecialPriceStrategy, CatalogRuleStrategy
│   └── StrategyEligibilityGuards/  CouponOnly, Ampromo, ZeroPrice
├── Observer/               CouponPost, CartUpdate, CartPageLoad, CollectTotalsMessageQueue
├── Plugin/SalesRule/Model/
│   ├── ValidatorPlugin.php                      around Validator::process()
│   ├── Converter/ToDataModelPlugin.php          REST extension attribute (model → data)
│   ├── Converter/ToModelPlugin.php              REST extension attribute (data → model)
│   └── ResourceModel/Rule/DataProviderPlugin.php  bypass default on the rule form
├── Service/                Config, DiscountExclusionManager, MaxDiscountCalculator,
│                           ExclusionResultCollector, ExclusionMessageBuilder
├── etc/                    module, di, acl, config, system, db_schema, extension_attributes,
│                           frontend/events.xml, graphql/events.xml
├── i18n/                   en_US, de_DE, es_ES, fr_FR, it_IT
└── view/adminhtml/ui_component/sales_rule_form.xml
Test/Unit/                  PHPUnit tests, mirroring src/
```

`Api/MessageProcessorInterface`, `Service/MessageProcessor` and `Model/MessageGroups` are deprecated, unused, and will be removed in 2.0.0.

### Event Observers

```xml
<!-- etc/frontend/events.xml -->
<!-- Cart page load: display queued messages -->
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

<!-- etc/graphql/events.xml -->
<event name="sales_quote_collect_totals_after">
    <observer name="discount_exclusion_graphql_message_queue"
              instance="PixelPerfect\DiscountExclusion\Observer\CollectTotalsMessageQueueObserver"/>
</event>
```

## Requirements

- PHP 8.3, 8.4 or 8.5 (`php: ^8.3`; the code uses typed class constants, so PHP 8.2 is not supported)
- Magento Open Source or Adobe Commerce 2.4 (`magento/framework: ^103.0`). Because of the PHP requirement this means 2.4.7 or later in practice.
- Magento modules: Catalog, CatalogRule, Checkout, Config, Customer, Quote, SalesRule, Store
- No Hyvä dependency. The module ships no storefront templates or JavaScript; customer messages go through Magento's message manager.

## Installation

```bash
composer require pixelperfectat/magento2-module-discount-exclusion
bin/magento module:enable PixelPerfect_DiscountExclusion
bin/magento setup:upgrade
bin/magento cache:flush
```

## Uninstallation

```bash
bin/magento module:disable PixelPerfect_DiscountExclusion
composer remove pixelperfectat/magento2-module-discount-exclusion
bin/magento setup:upgrade
bin/magento cache:flush
```

Alternatively, `bin/magento module:uninstall PixelPerfect_DiscountExclusion` disables the module and removes the Composer package in one step.

The module adds the `bypass_discount_exclusion` column to the `salesrule` table. Check whether it is still there after removal and drop it manually if you no longer need it.

## Development and checks

The checks run in a PHP 8.3 environment with the dev dependencies installed (`composer install`):

```bash
composer check          # all of the below, in this order
composer ci:validate    # composer validate
composer ci:normalize   # composer normalize --dry-run
composer lint           # parallel-lint
composer cs             # phpcs (PixelPerfect standard)
composer stan           # PHPStan (level from pixelperfectat/coding-standard)
composer test           # PHPUnit unit tests
composer infection      # Infection mutation testing (needs Xdebug or PCOV for coverage)
```

`ci:normalize` is not part of `check`. The unit tests cover every class in `src/` except the deprecated `MessageProcessor`. There are no integration tests against a running Magento instance.

## Translations

Supported locales: `en_US`, `de_DE`, `it_IT`, `fr_FR`, `es_ES`

All exclusion messages, bypass messages, admin labels, and tooltips are translatable.

## Debugging

All log lines start with `DiscountExclusion:` and go through Magento's default PSR logger:

- Decisions an operator usually needs (a discount was blocked, a bypass discount was capped, a coupon was removed) are logged at `info` level and end up in `var/log/system.log`.
- The step-by-step trail (each guard and strategy with its result, the bypass calculation) is logged at `debug` level in `var/log/debug.log`, which Magento only writes when debug logging is enabled (`bin/magento setup:config:set --enable-debug-logging=true`).

Key places to set breakpoints:

- `ValidatorPlugin::aroundProcess()` — Entry point for discount validation
- `ValidatorPlugin::handleBypass()` — Bypass flow with max-discount logic
- `DiscountExclusionManager::shouldExcludeFromDiscount()` — Guard and strategy evaluation
- `MaxDiscountCalculator::calculate()` — Max-discount computation
- `CouponPostObserver::execute()` — Coupon apply message display
- `CartUpdateObserver::execute()` — Cart change message queuing

## Roadmap

- Additional default guards and strategies
- Event dispatching for observability
- Compatibility with third-party discount modules
- Exclusion messages in GraphQL responses for headless storefronts

## License

[MIT](LICENSE)

## Support

For issues, feature requests, or questions, please [open an issue](https://github.com/pixelperfectat/magento2-module-discount-exclusion/issues).

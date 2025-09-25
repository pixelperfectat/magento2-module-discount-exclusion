# PixelPerfect Discount Exclusion

Extensible Magento 2 module that prevents applying shopping cart (sales rule) discounts to products already discounted by other mechanisms.

Status: Active development. APIs and behavior may change without backward compatibility.

## What it does

- Blocks additional cart discounts when a product is already discounted (e.g., special price, catalog price rules).
- Leaves other cart items eligible for discounts.
- Provides a pluggable, DI-driven architecture to decide:
  - If exclusion strategies should run (Strategy Eligibility Guards).
  - Which exclusion strategies apply to a product.

## Concepts

### Strategy Eligibility Guards

Guards decide whether exclusion strategies should be processed for a given quote item. They act as an early gate and short-circuit evaluation when strategies don’t make sense.

Interface:
```php
interface StrategyEligibilityGuardInterface { 
    public function canProcess: bool; 
}
```
Typical use cases for guards:
- Zero-price items (no further discount applicable).
- Coupon/rule types that imply free gifts or similar (skip exclusion strategies).
- Storefront conditions (customer group, website, date windows).
- Product-type or item-state filters to avoid unnecessary strategy work.

Example (Zero price guard):

```php
class ZeroPriceGuard implements StrategyEligibilityGuardInterface { 
    public function canProcess(
    ProductInterface|Product product,
    AbstractItemitem, 
    ?string couponCode
    ): bool { 
        returnproduct->getFinalPrice() > 0; 
    }
}
```

Configure guards via di.xml by injecting an array into the manager (see “Configuration” below).

### Discount Exclusion Strategies

Strategies determine whether a product should be excluded from additional cart discounts.

Interface:

```php
interface DiscountExclusionStrategyInterface { 
    public function shouldExcludeFromDiscount: bool; 
}
```
Default strategies:
- SpecialPriceStrategy: excludes items where an active special price equals the final price.
- CatalogRuleStrategy: excludes items affected by catalog price rules.

Add custom strategies via DI; the manager evaluates them in order and returns true on first match.

## How it works (high level)

1. The validator selects the product relevant for pricing (handles complex types) and reads the coupon code.
2. Strategy Eligibility Guards run. If any guard returns false, strategies are skipped and the item remains eligible for cart discounts.
3. If guards allow, exclusion strategies run. If any strategy returns true, the item is excluded from additional cart discounts.
4. A user-friendly message can be shown explaining why the coupon did not apply to that product.

## Usage in your module

### 1) Add a Strategy Eligibility Guard

- Implement StrategyEligibilityGuardInterface.
- Register it via di.xml under the manager’s strategyEligibilityGuards argument.

Example di.xml:

```xml
    <type name="PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager">
        <arguments>
            <argument name="strategyEligibilityGuards" xsi:type="array">
                <item name="ampromo" xsi:type="object">PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\Ampromo</item>
                <item name="zero_price" xsi:type="object">PixelPerfect\DiscountExclusion\Model\StrategyEligibilityGuards\ZeroPrice</item>
            </argument>
            ....
        </arguments>
    </type>
```

Tips:
- Order matters. Place cheap, frequently-triggered guards first to short-circuit quickly.
- Guards answer “should strategies run?” Keep exclusion logic inside strategies.

### 2) Add a Discount Exclusion Strategy

- Implement DiscountExclusionStrategyInterface.
- Register it via di.xml under the manager’s strategies argument.

Example di.xml:
```xml
<type name="PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager">
    <arguments>
        ....
        <argument name="strategies" xsi:type="array">
            <item name="special_price" xsi:type="object">PixelPerfect\DiscountExclusion\Model\Strategy\SpecialPriceStrategy</item>
            <item name="catalog_rule" xsi:type="object">PixelPerfect\DiscountExclusion\Model\Strategy\CatalogRuleStrategy</item>
        </argument>
    </arguments>
</type>
```

Guidelines:
- Strategies answer “should this item be excluded from additional cart discounts?”
- Return true to exclude; false to allow other strategies to decide.

## Configuration

- All wiring is done via dependency injection.
- Add or reorder guards and strategies by adjusting the arrays injected into:
  - strategies
  - strategyEligibilityGuards
- No admin configuration is required.

## Requirements

- Magento 2.4.x
- PHP 8.2+

## Installation

```bash
 composer require pixelperfectat/magento2-module-discount-exclusion 
 bin/magento module:enable PixelPerfect_DiscountExclusion 
 bin/magento setup:upgrade 
 bin/magento cache:flush
```

## Roadmap

- Additional default guards and strategies.
- Optional admin configuration.
- Developer experience improvements.
- API refinements (subject to change).

Note: This module is under active development. Names, interfaces, and behavior may change without notice.
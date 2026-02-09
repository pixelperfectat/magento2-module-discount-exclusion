# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-02-09

### Added

- Per-rule bypass toggle (`bypass_discount_exclusion`) on the Rule Information tab in admin, allowing specific cart rules to override discount exclusion
- Max-discount logic for bypassed rules: customer receives `max(existing discount, rule discount)` calculated from the regular price, instead of stacking discounts
- Discount exclusion messages on cart add, update, and delete — not just on coupon apply
- Session-based message queuing so exclusion messages display only on the cart page, avoiding confusing messages on PLP/PDP
- Translatable bypass notification messages (adjusted and existing-better) for percentage and fixed-amount rules across all five supported locales (en_US, de_DE, it_IT, fr_FR, es_ES)
- PHPStan level 6 static analysis with `bitexpert/phpstan-magento` extension

### Changed

- Cart page messages are now queued in the checkout session and displayed by `CartPageLoadObserver` instead of being shown immediately on the triggering page
- Bypass tooltip updated to describe max-discount behavior instead of full stacking

### Removed

- `BypassExclusion` guard — replaced by plugin-level bypass handling with max-discount logic

### Fixed

- Double percent signs (`%%`) in bypass discount messages now render correctly as single `%`

## [0.1.0] - 2026-01-21

### Added

- Initial release with around plugin on `Magento\SalesRule\Model\Validator::process()`
- Strategy Eligibility Guards: CouponOnly, Ampromo, ZeroPrice
- Discount Exclusion Strategies: SpecialPriceStrategy, CatalogRuleStrategy
- Exclusion Result Collector for consolidated message display
- User-friendly coupon exclusion messages with automatic coupon removal
- Admin configuration to enable/disable module per store view
- Translations for en_US, de_DE, it_IT, fr_FR, es_ES
- Unit tests for all core components

[Unreleased]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/compare/0.2.0...HEAD
[0.2.0]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/releases/tag/0.1.0

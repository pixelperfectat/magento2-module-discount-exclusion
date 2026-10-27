# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Unit tests for `CouponPostObserver`, `DataProviderPlugin` and the store-scoped config flags, plus exact-text tests for every customer message and characterization tests that pin the current four-decimal bypass cap.
- Infection mutation testing (`composer infection`), run as part of `composer check`.

### Changed

- `composer.json` now declares the Magento modules the code uses: `module-catalog-rule`, `module-checkout`, `module-config`, `module-customer`, `module-quote` and `module-store`.
- The Composer dist archive no longer contains the unit tests, the CI workflow or the QA tool configs.
- Development toolchain moved to `pixelperfectat/coding-standard` 0.2.0 (PHPStan 2).
- README documents all three configuration settings, the CLI command, the REST extension attribute, uninstallation and the known limitations.
- PHP 8.5 is listed as supported (the `^8.3` constraint already allowed it); the checks pass on 8.5. From PHP 8.4 on, PHP's own `round()` treats exact halves differently, so a four-decimal intermediate amount such as 3.09845 becomes 3.0984 instead of 3.0985. Magento's own rounding follows the same PHP behaviour.

### Deprecated

- `Api\MessageProcessorInterface`, `Service\MessageProcessor`, `Model\MessageGroups` and `Model\SessionKeys::PROCESSED_PRODUCT_IDS` are unused and will be removed in 2.0.0.
- `ExclusionResultCollectorInterface::hasAnyExcludedItems()`, `hasAnyBypassedItems()` and `getCouponCodes()` are unused and will be removed in 2.0.0. Use `hasExcludedItems()` / `hasBypassedItems()` with a coupon code.

### Fixed

- Three source files carried an "All rights reserved" header that contradicted the MIT license.

## [1.0.2] - 2026-07-09

### Fixed

- Bypass cap no longer applies a phantom one-cent discount when a coupon's percentage equals an existing catalog price rule of the same percentage. The `ADJUSTED`/`EXISTING_BETTER` decision used a `0.001` epsilon — finer than the smallest representable currency unit — so a catalog rule's cent-rounded final price (e.g. `22.425` → `22.43`) left a sub-cent residue that was misclassified as an adjustment and rounded up to a visible `0.01`. The classification now requires at least one representable cent of additional discount across the line (#8).

## [1.0.1] - 2026-07-09

### Fixed

- Bypass discount cap now also updates `base_discount_amount`. Previously `handleBypassAdjusted()` capped only `discount_amount`, leaving the base discount at its full uncapped value — this made `base_grand_total` diverge from `grand_total` on single-currency stores and caused downstream consumers reading the base amount (e.g. payment integrations settling on `base_grand_total`) to charge the wrong amount (#5).

## [1.0.0] - 2026-07-06

### Added

- MIT license.
- Shared PixelPerfect coding standard, PHPStan configuration and a CI workflow.

### Changed

- Requires PHP 8.3 (`^8.3`, previously `^8.2`): the code uses typed class constants.
- The module follows semantic versioning from this release on.

## [0.2.1] - 2026-05-22

### Added

- `show_messages` setting (Show Customer Messages) to turn the customer-facing exclusion and bypass messages off.
- Item data markers on quote items for each decision: `pp_discount_excluded`, `pp_discount_exclusion_reason`, `pp_discount_bypass_adjusted` and `pp_discount_exclusion_params`.
- GraphQL-area observer on `sales_quote_collect_totals_after` that queues the messages in the checkout session.
- REST API extension attribute `bypass_discount_exclusion` on `Magento\SalesRule\Api\Data\RuleInterface`.
- `bypass_default` setting (Bypass Default for New Rules), used as the toggle's default on the cart price rule form.
- CLI command `discount-exclusion:sync-bypass-default` (with `--dry-run`) to apply the bypass default to existing rules.

### Fixed

- Removed a leading newline from `registration.php`.

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

[Unreleased]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/compare/1.0.2...HEAD
[1.0.2]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/compare/0.2.1...1.0.0
[0.2.1]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/pixelperfectat/magento2-module-discount-exclusion/releases/tag/0.1.0

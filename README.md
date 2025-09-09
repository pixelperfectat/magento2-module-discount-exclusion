# PixelPerfect DiscountExclusion

## Overview
The PixelPerfect DiscountExclusion module provides a flexible and extensible system for preventing additional discounts on products that are already discounted in Magento 2.

## Features
- Prevent applying shopping cart rules to products with existing discounts
- Flexible strategy pattern implementation for discount exclusion
- Easily extendable with custom discount exclusion strategies
- Supports multiple exclusion criteria out of the box

## Installation
```bash
composer require pixelperfectat/magento2-module-discount-exclusion
php bin/magento module:enable PixelPerfect_DiscountExclusion
php bin/magento setup:upgrade
```

## Strategies
The module implements a strategy pattern that allows easy addition of discount exclusion rules.

### Default Strategies
- Special Price Strategy: Excludes products with existing special prices
- Catalog Rule Strategy: Excludes products affected by catalog price rules

### Adding Custom Strategies
To add a custom discount exclusion strategy:

1. Create a strategy class implementing `DiscountExclusionStrategyInterface`:
```php
class MyCustomDiscountStrategy implements DiscountExclusionStrategyInterface
{
    public function shouldExcludeFromDiscount(Product $product, Item $item): bool
    {
        // Custom exclusion logic
        return false; // or true based on your conditions
    }
}
```

2. Add the strategy via `di.xml`:
```xml
<type name="PixelPerfect\DiscountExclusion\Service\DiscountExclusionManager">
    <arguments>
        <argument name="strategies" xsi:type="array">
            <item name="my_custom_strategy" xsi:type="object">
                Vendor\Module\Model\Strategy\MyCustomDiscountStrategy
            </item>
        </argument>
    </arguments>
</type>
```

Alternatively, dynamically add strategies programmatically:
```php
$discountExclusionManager->addStrategy($myCustomStrategy);
```

## Compatibility
- Magento 2.4.x
- PHP 8.2+

## License
This project is licensed under the MIT License - see the full license text below:

MIT License

Copyright (c) 2024 André Flitsch

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## Support
For support, please contact:
- Email: pixelhed@pixelperfect.at
- GitHub Issues: [Project Issues Page](https://github.com/pixelperfectat/magento2-module-discount-exclusion/issues)

## Contributing
Contributions are welcome! Please submit pull requests to the repository.
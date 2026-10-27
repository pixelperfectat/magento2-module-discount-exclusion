<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Plugin\SalesRule\Model\ResourceModel\Rule;

use Magento\SalesRule\Model\Rule\DataProvider;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\ResourceModel\Rule\DataProviderPlugin;

class DataProviderPluginTest extends TestCase
{
    /**
     * @dataProvider bypassDefaultProvider
     */
    public function testInjectsConfiguredBypassDefaultIntoFormMeta(bool $bypassDefault, int $expected): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isBypassDefault')->willReturn($bypassDefault);
        $plugin = new DataProviderPlugin($config);

        $result = $plugin->afterGetMeta($this->createMock(DataProvider::class), []);

        $this->assertSame(
            $expected,
            $result['rule_information']['children']['bypass_discount_exclusion']
                ['arguments']['data']['config']['default']
        );
    }

    /**
     * @return array<string, array{bool, int}>
     */
    public static function bypassDefaultProvider(): array
    {
        return [
            'bypass enabled by default' => [true, 1],
            'bypass disabled by default' => [false, 0],
        ];
    }

    public function testKeepsExistingFormMeta(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isBypassDefault')->willReturn(true);
        $plugin = new DataProviderPlugin($config);

        $meta = [
            'actions' => ['children' => ['discount_amount' => ['arguments' => []]]],
            'rule_information' => [
                'children' => [
                    'name' => ['arguments' => ['data' => ['config' => ['label' => 'Rule Name']]]],
                    'bypass_discount_exclusion' => [
                        'arguments' => ['data' => ['config' => ['sortOrder' => 125]]],
                    ],
                ],
            ],
        ];

        $result = $plugin->afterGetMeta($this->createMock(DataProvider::class), $meta);

        $this->assertSame($meta['actions'], $result['actions']);
        $this->assertSame(
            $meta['rule_information']['children']['name'],
            $result['rule_information']['children']['name']
        );
        $this->assertSame(
            ['sortOrder' => 125, 'default' => 1],
            $result['rule_information']['children']['bypass_discount_exclusion']['arguments']['data']['config']
        );
    }
}

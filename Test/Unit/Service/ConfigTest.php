<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Service\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testIsBypassDefaultReturnsTrueWhenEnabled(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with(ConfigInterface::XML_PATH_BYPASS_DEFAULT)
            ->willReturn(true);

        $this->assertTrue($this->config->isBypassDefault());
    }

    public function testIsBypassDefaultReturnsFalseWhenDisabled(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with(ConfigInterface::XML_PATH_BYPASS_DEFAULT)
            ->willReturn(false);

        $this->assertFalse($this->config->isBypassDefault());
    }

    /**
     * @dataProvider storeScopedFlagProvider
     */
    public function testStoreScopedFlagsAreReadForTheGivenStore(string $method, string $path, bool $value): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with($path, ScopeInterface::SCOPE_STORE, 3)
            ->willReturn($value);

        $this->assertSame($value, $this->config->{$method}(3));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function storeScopedFlagProvider(): array
    {
        return [
            'enabled on' => ['isEnabled', ConfigInterface::XML_PATH_ENABLED, true],
            'enabled off' => ['isEnabled', ConfigInterface::XML_PATH_ENABLED, false],
            'messages on' => ['isMessagesEnabled', ConfigInterface::XML_PATH_SHOW_MESSAGES, true],
            'messages off' => ['isMessagesEnabled', ConfigInterface::XML_PATH_SHOW_MESSAGES, false],
        ];
    }

    public function testStoreScopedFlagsFallBackToCurrentStoreWithoutStoreId(): void
    {
        $this->scopeConfig->expects($this->exactly(2))
            ->method('isSetFlag')
            ->withConsecutive(
                [ConfigInterface::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, null],
                [ConfigInterface::XML_PATH_SHOW_MESSAGES, ScopeInterface::SCOPE_STORE, null],
            )
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled());
        $this->assertTrue($this->config->isMessagesEnabled());
    }

    /**
     * Every path the code reads must be declared in system.xml and have a default in config.xml.
     */
    public function testConfigPathsAreDeclaredInSystemXmlAndConfigXml(): void
    {
        $etc = dirname(__DIR__, 3) . '/src/etc';
        $system = simplexml_load_file($etc . '/adminhtml/system.xml');
        $defaults = simplexml_load_file($etc . '/config.xml');
        $this->assertNotFalse($system);
        $this->assertNotFalse($defaults);

        $declared = [];
        foreach ($system->xpath('//section') as $section) {
            foreach ($section->group as $group) {
                foreach ($group->field as $field) {
                    $declared[] = $section['id'] . '/' . $group['id'] . '/' . $field['id'];
                }
            }
        }

        foreach ([
            ConfigInterface::XML_PATH_ENABLED,
            ConfigInterface::XML_PATH_SHOW_MESSAGES,
            ConfigInterface::XML_PATH_BYPASS_DEFAULT,
        ] as $path) {
            $this->assertContains($path, $declared, "$path is not declared in system.xml");
            $this->assertCount(1, $defaults->xpath('/config/default/' . $path), "$path has no default in config.xml");
        }
    }
}

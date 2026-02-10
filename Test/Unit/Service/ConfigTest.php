<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
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
}

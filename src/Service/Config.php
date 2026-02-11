<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;

readonly class Config implements ConfigInterface
{
    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isMessagesEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_MESSAGES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isBypassDefault(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_BYPASS_DEFAULT);
    }
}

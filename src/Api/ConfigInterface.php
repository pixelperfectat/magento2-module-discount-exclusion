<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

interface ConfigInterface
{
    public const XML_PATH_ENABLED = 'discount_exclusion/general/enabled';
    public const XML_PATH_BYPASS_DEFAULT = 'discount_exclusion/general/bypass_default';

    /**
     * Check if the discount exclusion module is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool;

    /**
     * Get the default value for the bypass discount exclusion toggle on new rules
     *
     * @return bool
     */
    public function isBypassDefault(): bool;
}

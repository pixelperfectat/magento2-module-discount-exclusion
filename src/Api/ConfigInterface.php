<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Api;

interface ConfigInterface
{
    public const XML_PATH_ENABLED = 'discount_exclusion/general/enabled';

    /**
     * Check if the discount exclusion module is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool;
}

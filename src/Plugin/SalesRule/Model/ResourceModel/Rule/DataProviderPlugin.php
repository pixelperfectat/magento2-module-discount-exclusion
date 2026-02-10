<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\ResourceModel\Rule;

use Magento\SalesRule\Model\Rule\DataProvider;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;

class DataProviderPlugin
{
    public function __construct(
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * Inject the configured bypass default into the form field metadata
     *
     * @param DataProvider            $subject
     * @param array<string, mixed>    $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(DataProvider $subject, array $result): array
    {
        $result['rule_information']['children']['bypass_discount_exclusion']
            ['arguments']['data']['config']['default'] = (int) $this->config->isBypassDefault();

        return $result;
    }
}

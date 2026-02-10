<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\Converter;

use Magento\SalesRule\Model\Converter\ToModel;
use Magento\SalesRule\Model\Data\Rule as RuleDataModel;
use Magento\SalesRule\Model\Rule;

class ToModelPlugin
{
    /**
     * Copy bypass_discount_exclusion from extension attributes to rule model
     *
     * @param ToModel       $subject
     * @param Rule          $result
     * @param RuleDataModel $dataModel
     * @return Rule
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterToModel(ToModel $subject, Rule $result, RuleDataModel $dataModel): Rule
    {
        $extensionAttributes = $dataModel->getExtensionAttributes();

        if ($extensionAttributes !== null && $extensionAttributes->getBypassDiscountExclusion() !== null) {
            $result->setData(
                'bypass_discount_exclusion',
                (int) $extensionAttributes->getBypassDiscountExclusion()
            );
        }

        return $result;
    }
}

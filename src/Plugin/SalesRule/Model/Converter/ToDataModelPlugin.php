<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\Converter;

use Magento\SalesRule\Api\Data\RuleExtensionFactory;
use Magento\SalesRule\Model\Converter\ToDataModel;
use Magento\SalesRule\Model\Data\Rule as RuleDataModel;
use Magento\SalesRule\Model\Rule;

class ToDataModelPlugin
{
    public function __construct(
        private readonly RuleExtensionFactory $ruleExtensionFactory
    ) {
    }

    /**
     * Copy bypass_discount_exclusion from rule model to extension attributes
     *
     * @param ToDataModel   $subject
     * @param RuleDataModel $result
     * @param Rule          $ruleModel
     * @return RuleDataModel
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterToDataModel(ToDataModel $subject, RuleDataModel $result, Rule $ruleModel): RuleDataModel
    {
        $extensionAttributes = $result->getExtensionAttributes() ?? $this->ruleExtensionFactory->create();
        $extensionAttributes->setBypassDiscountExclusion((bool) $ruleModel->getData('bypass_discount_exclusion'));
        $result->setExtensionAttributes($extensionAttributes);

        return $result;
    }
}

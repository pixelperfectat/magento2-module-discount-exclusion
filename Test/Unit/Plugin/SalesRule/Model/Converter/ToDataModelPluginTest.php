<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Plugin\SalesRule\Model\Converter;

use Magento\Framework\Api\ExtensionAttributesInterface;
use Magento\SalesRule\Model\Converter\ToDataModel;
use Magento\SalesRule\Model\Data\Rule as RuleDataModel;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\Converter\ToDataModelPlugin;

// Stubs for generated classes that don't exist outside Magento runtime
if (!interface_exists(\Magento\SalesRule\Api\Data\RuleExtensionInterface::class)) {
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
    class_alias(ExtensionAttributesInterface::class, \Magento\SalesRule\Api\Data\RuleExtensionInterface::class);
}

if (!class_exists(\Magento\SalesRule\Api\Data\RuleExtensionFactory::class)) {
    eval('namespace Magento\SalesRule\Api\Data; class RuleExtensionFactory { public function create() { return null; } }');
}

class ToDataModelPluginTest extends TestCase
{
    private MockObject $extensionFactory;
    private ToDataModel&MockObject $subject;
    private ToDataModelPlugin $plugin;

    protected function setUp(): void
    {
        $this->extensionFactory = $this->getMockBuilder(\Magento\SalesRule\Api\Data\RuleExtensionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->subject = $this->createMock(ToDataModel::class);
        $this->plugin = new ToDataModelPlugin($this->extensionFactory);
    }

    public function testSetsExtensionAttributeWhenBypassEnabled(): void
    {
        $ruleModel = $this->createRuleMock(1);
        $extensionAttributes = $this->createExtensionAttributesMock();
        $dataModel = $this->createDataModelMock($extensionAttributes);

        $extensionAttributes->expects($this->once())
            ->method('setBypassDiscountExclusion')
            ->with(true);

        $dataModel->expects($this->once())
            ->method('setExtensionAttributes')
            ->with($extensionAttributes);

        $this->plugin->afterToDataModel($this->subject, $dataModel, $ruleModel);
    }

    public function testSetsExtensionAttributeWhenBypassDisabled(): void
    {
        $ruleModel = $this->createRuleMock(0);
        $extensionAttributes = $this->createExtensionAttributesMock();
        $dataModel = $this->createDataModelMock($extensionAttributes);

        $extensionAttributes->expects($this->once())
            ->method('setBypassDiscountExclusion')
            ->with(false);

        $this->plugin->afterToDataModel($this->subject, $dataModel, $ruleModel);
    }

    public function testCreatesExtensionAttributesWhenNull(): void
    {
        $ruleModel = $this->createRuleMock(1);
        $extensionAttributes = $this->createExtensionAttributesMock();
        $dataModel = $this->createDataModelMock(null);

        $this->extensionFactory->expects($this->once())
            ->method('create')
            ->willReturn($extensionAttributes);

        $extensionAttributes->expects($this->once())
            ->method('setBypassDiscountExclusion')
            ->with(true);

        $this->plugin->afterToDataModel($this->subject, $dataModel, $ruleModel);
    }

    public function testHandlesNullBypassValue(): void
    {
        $ruleModel = $this->createRuleMock(null);
        $extensionAttributes = $this->createExtensionAttributesMock();
        $dataModel = $this->createDataModelMock($extensionAttributes);

        $extensionAttributes->expects($this->once())
            ->method('setBypassDiscountExclusion')
            ->with(false);

        $this->plugin->afterToDataModel($this->subject, $dataModel, $ruleModel);
    }

    private function createRuleMock(int|null $bypassValue): Rule&MockObject
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();

        $rule->method('getData')
            ->with('bypass_discount_exclusion')
            ->willReturn($bypassValue);

        return $rule;
    }

    private function createExtensionAttributesMock(): MockObject
    {
        return $this->getMockBuilder(\Magento\SalesRule\Api\Data\RuleExtensionInterface::class)
            ->addMethods(['setBypassDiscountExclusion', 'getBypassDiscountExclusion'])
            ->getMockForAbstractClass();
    }

    private function createDataModelMock(MockObject|null $extensionAttributes): RuleDataModel&MockObject
    {
        $dataModel = $this->createMock(RuleDataModel::class);
        $dataModel->method('getExtensionAttributes')->willReturn($extensionAttributes);

        return $dataModel;
    }
}

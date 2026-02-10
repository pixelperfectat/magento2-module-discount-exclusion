<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Plugin\SalesRule\Model\Converter;

use Magento\Framework\Api\ExtensionAttributesInterface;
use Magento\SalesRule\Model\Converter\ToModel;
use Magento\SalesRule\Model\Data\Rule as RuleDataModel;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Plugin\SalesRule\Model\Converter\ToModelPlugin;

// Stub for generated interface that doesn't exist outside Magento runtime
if (!interface_exists(\Magento\SalesRule\Api\Data\RuleExtensionInterface::class)) {
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
    class_alias(ExtensionAttributesInterface::class, \Magento\SalesRule\Api\Data\RuleExtensionInterface::class);
}

class ToModelPluginTest extends TestCase
{
    private ToModel&MockObject $subject;
    private ToModelPlugin $plugin;

    protected function setUp(): void
    {
        $this->subject = $this->createMock(ToModel::class);
        $this->plugin = new ToModelPlugin();
    }

    public function testSetsDataWhenBypassEnabled(): void
    {
        $extensionAttributes = $this->createExtensionAttributesMock(true);
        $dataModel = $this->createDataModelMock($extensionAttributes);
        $result = $this->createRuleMock();

        $result->expects($this->once())
            ->method('setData')
            ->with('bypass_discount_exclusion', 1);

        $this->plugin->afterToModel($this->subject, $result, $dataModel);
    }

    public function testSetsDataWhenBypassDisabled(): void
    {
        $extensionAttributes = $this->createExtensionAttributesMock(false);
        $dataModel = $this->createDataModelMock($extensionAttributes);
        $result = $this->createRuleMock();

        $result->expects($this->once())
            ->method('setData')
            ->with('bypass_discount_exclusion', 0);

        $this->plugin->afterToModel($this->subject, $result, $dataModel);
    }

    public function testSkipsWhenExtensionAttributesNull(): void
    {
        $dataModel = $this->createDataModelMock(null);
        $result = $this->createRuleMock();

        $result->expects($this->never())->method('setData');

        $this->plugin->afterToModel($this->subject, $result, $dataModel);
    }

    public function testSkipsWhenBypassValueNull(): void
    {
        $extensionAttributes = $this->createExtensionAttributesMock(null);
        $dataModel = $this->createDataModelMock($extensionAttributes);
        $result = $this->createRuleMock();

        $result->expects($this->never())->method('setData');

        $this->plugin->afterToModel($this->subject, $result, $dataModel);
    }

    private function createRuleMock(): Rule&MockObject
    {
        return $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData'])
            ->getMock();
    }

    private function createExtensionAttributesMock(?bool $bypassValue): MockObject
    {
        $mock = $this->getMockBuilder(\Magento\SalesRule\Api\Data\RuleExtensionInterface::class)
            ->addMethods(['getBypassDiscountExclusion', 'setBypassDiscountExclusion'])
            ->getMockForAbstractClass();

        $mock->method('getBypassDiscountExclusion')->willReturn($bypassValue);

        return $mock;
    }

    private function createDataModelMock(MockObject|null $extensionAttributes): RuleDataModel&MockObject
    {
        $dataModel = $this->createMock(RuleDataModel::class);
        $dataModel->method('getExtensionAttributes')->willReturn($extensionAttributes);

        return $dataModel;
    }
}

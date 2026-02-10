<?php declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Test\Unit\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use PixelPerfect\DiscountExclusion\Console\Command\SyncBypassDefaultCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncBypassDefaultCommandTest extends TestCase
{
    private ConfigInterface&MockObject $config;
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private InputInterface&MockObject $input;
    private OutputInterface&MockObject $output;
    private SyncBypassDefaultCommand $command;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->input = $this->createMock(InputInterface::class);
        $this->output = $this->createMock(OutputInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->with('salesrule')->willReturn('salesrule');

        $this->command = new SyncBypassDefaultCommand(
            $this->config,
            $this->resourceConnection
        );
    }

    public function testDryRunReportsCount(): void
    {
        $this->config->method('isBypassDefault')->willReturn(true);
        $this->input->method('getOption')->with('dry-run')->willReturn(true);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->with($select)->willReturn('5');

        $this->output->expects($this->once())
            ->method('writeln')
            ->with('Dry run: 5 rule(s) would be updated to bypass=enabled');

        $this->invokeExecute();
    }

    public function testDryRunWithBypassDisabled(): void
    {
        $this->config->method('isBypassDefault')->willReturn(false);
        $this->input->method('getOption')->with('dry-run')->willReturn(true);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->with($select)->willReturn('3');

        $this->output->expects($this->once())
            ->method('writeln')
            ->with('Dry run: 3 rule(s) would be updated to bypass=disabled');

        $this->invokeExecute();
    }

    public function testExecuteUpdatesRules(): void
    {
        $this->config->method('isBypassDefault')->willReturn(true);
        $this->input->method('getOption')->with('dry-run')->willReturn(false);

        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'salesrule',
                ['bypass_discount_exclusion' => 1],
                ['IFNULL(bypass_discount_exclusion, 0) != ?' => 1]
            )
            ->willReturn(7);

        $this->output->expects($this->once())
            ->method('writeln')
            ->with('Updated 7 rule(s) to bypass=enabled');

        $this->invokeExecute();
    }

    public function testExecuteWithBypassDisabled(): void
    {
        $this->config->method('isBypassDefault')->willReturn(false);
        $this->input->method('getOption')->with('dry-run')->willReturn(false);

        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'salesrule',
                ['bypass_discount_exclusion' => 0],
                ['IFNULL(bypass_discount_exclusion, 0) != ?' => 0]
            )
            ->willReturn(2);

        $this->output->expects($this->once())
            ->method('writeln')
            ->with('Updated 2 rule(s) to bypass=disabled');

        $this->invokeExecute();
    }

    public function testExecuteWithZeroUpdates(): void
    {
        $this->config->method('isBypassDefault')->willReturn(true);
        $this->input->method('getOption')->with('dry-run')->willReturn(false);

        $this->connection->method('update')->willReturn(0);

        $this->output->expects($this->once())
            ->method('writeln')
            ->with('Updated 0 rule(s) to bypass=enabled');

        $this->invokeExecute();
    }

    /**
     * Invoke the protected execute method via reflection
     */
    private function invokeExecute(): int
    {
        $method = new \ReflectionMethod($this->command, 'execute');

        return $method->invoke($this->command, $this->input, $this->output);
    }
}

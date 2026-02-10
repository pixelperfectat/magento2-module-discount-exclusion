<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountExclusion\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use PixelPerfect\DiscountExclusion\Api\ConfigInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncBypassDefaultCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ResourceConnection $resourceConnection,
    ) {
        parent::__construct();
    }

    /**
     * Configure the CLI command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('discount-exclusion:sync-bypass-default')
            ->setDescription('Sync all sales rules to the configured bypass default value')
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Preview changes without applying them'
            );
    }

    /**
     * Execute the sync command
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $default = (int) $this->config->isBypassDefault();
        $label = $default ? 'enabled' : 'disabled';
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('salesrule');

        if ($input->getOption(self::OPTION_DRY_RUN)) {
            $count = (int) $connection->fetchOne(
                $connection->select()
                    ->from($tableName, ['COUNT(*)'])
                    ->where('IFNULL(bypass_discount_exclusion, 0) != ?', $default)
            );

            $output->writeln(sprintf('Dry run: %d rule(s) would be updated to bypass=%s', $count, $label));

            return Cli::RETURN_SUCCESS;
        }

        $count = (int) $connection->update(
            $tableName,
            ['bypass_discount_exclusion' => $default],
            ['IFNULL(bypass_discount_exclusion, 0) != ?' => $default]
        );

        $output->writeln(sprintf('Updated %d rule(s) to bypass=%s', $count, $label));

        return Cli::RETURN_SUCCESS;
    }
}

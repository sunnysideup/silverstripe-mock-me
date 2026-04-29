<?php

namespace Sunnysideup\MockMe\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Sunnysideup\MockMe\Api\CreateMockData;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create Mock Data Task
 *
 * Generates realistic mock data for all DataObject classes in the project.
 * Includes automatic validation error fixing and circular reference prevention.
 *
 * Usage:
 *   vendor/bin/sake dev/tasks/CreateMockDataRunner
 *   vendor/bin/sake dev/tasks/CreateMockDataRunner --reset
 */
class CreateMockDataRunner extends BuildTask
{
    protected static string $commandName = 'CreateMockDataRunner';

    protected string $title = 'Create Mock Data Runner';

    protected static string $description = 'Generates mock data for testing. Intelligently fills all DataObject '
                                            . 'classes with realistic test data based on field names and types.';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        // Increase time limit for large databases
        Environment::increaseTimeLimitTo();
        
        // Safety check: never run on live
        if (Director::isLive()) {
            $output->writeln('<error>DANGER: Cannot run mock data generator on LIVE environment!</error>');
            return Command::FAILURE;
        }
        
        // Check if reset/truncate flag is set
        if ($this->getShouldReset($input)) {
            $output->writeln('<error>WARNING: --reset flag detected. All database tables will be truncated!</error>');
            
            // Temporarily set the config
            Config::modify()->set(CreateMockData::class, 'truncate_before_create', true);
        }
        
        // Run the mock data generator
        $runner = new CreateMockData();
        $runner->run();

        return Command::SUCCESS;
    }
    
    /**
     * Check if we should reset/truncate the database
     */
    protected function getShouldReset(InputInterface $input): bool
    {
        return (bool)$input->getOption('reset') || (bool)$input->getOption('truncate');
    }

    /**
     * Define command-line options
     */
    public function getOptions(): array
    {
        return [
            new InputOption(
                'reset',
                null,
                InputOption::VALUE_NONE,
                'Truncate all database tables before creating mock data <error>[WARNING: Deletes ALL data!]</error>'
            ),
            new InputOption(
                'truncate',
                null,
                InputOption::VALUE_NONE,
                'Alias for --reset'
            ),
        ];
    }

    public function isEnabled(): bool
    {
        return Director::isDev();
    }
}

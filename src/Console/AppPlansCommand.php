<?php

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Config\ApplicationConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the configured plan names, one per line and nothing else, so deploy tooling can ask whether
 * an app defines a plan (`conductor app:plans | grep -qx sync`) before running it (CTAP-2143).
 *
 * A plan an environment has emptied (`sync: {}`) is not listed: `app:deploy --plan` rejects it
 * as invalid, so it is not a plan that can be run.
 */
class AppPlansCommand extends Command
{
    private const TYPES = ['deploy', 'build', 'snapshot'];

    private ApplicationConfig $applicationConfig;

    public function __construct(ApplicationConfig $applicationConfig, $name = null)
    {
        $this->applicationConfig = $applicationConfig;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('app:plans')
            ->setDescription('List configured plan names.')
            ->setHelp(
                "This command prints the names of the configured plans, one per line, with nothing else on stdout.\n"
                . 'An empty list is not an error.'
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Which plans to list. <comment>[allowed: %s]</comment>', implode(', ', self::TYPES)),
                'deploy'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plans = match ($input->getOption('type')) {
            'deploy' => $this->applicationConfig->getDeployConfig()->getPlans(),
            'build' => $this->applicationConfig->getBuildConfig()->getPlans(),
            'snapshot' => $this->applicationConfig->getSnapshotConfig()->getPlans(),
            // Thrown rather than written, so the message goes to stderr and stdout stays a plan list
            default => throw new InvalidOptionException(sprintf(
                'Unknown plan type "%s". Allowed: %s.',
                $input->getOption('type'),
                implode(', ', self::TYPES)
            )),
        };

        foreach ($plans as $name => $plan) {
            if (!empty($plan)) {
                $output->writeln((string) $name, OutputInterface::OUTPUT_RAW);
            }
        }

        return self::SUCCESS;
    }
}

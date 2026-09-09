<?php

namespace ConductorAppOrchestration\Console;

use ConductorAppOrchestration\Config\ApplicationConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigShowCommand extends Command
{
    private ApplicationConfig $applicationConfig;

    public function __construct(ApplicationConfig $applicationConfig, $name = null)
    {
        $this->applicationConfig = $applicationConfig;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('app:config:show')
            ->setDescription('Output application configuration.')
            ->setHelp("This command outputs application configuration matching a given search filter.")
            ->addArgument(
                'filter',
                InputArgument::OPTIONAL,
                'Pattern to filter config keys for display. Wildcards may be used.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getArgument('filter');
        $outputTable = new Table($output);
        $outputTable
            ->setHeaders(['Key', 'Value']);
        $this->expandToOutputRows($this->applicationConfig->toArray(), $outputTable, $filter);
        $outputTable->render();
        return self::SUCCESS;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function expandToOutputRows(
        array $data,
        Table $outputTable,
        ?string $filter = null,
        ?string $keyPrefix = null,
    ): void {
        ksort($data);
        foreach ($data as $key => $value) {
            if ($keyPrefix) {
                $key = "$keyPrefix/$key";
            }

            // Branch on is_array, not on !is_scalar: null is not scalar, and recursing into it
            // put it straight into ksort() (CTAP-1634).
            if (is_array($value) && [] !== $value) {
                $this->expandToOutputRows($value, $outputTable, $filter, $key);
                continue;
            }

            if (!$filter || fnmatch($filter, $key)) {
                $outputTable->addRow([$key, $this->formatValue($value)]);
            }
        }
    }

    /**
     * A config dump is read to answer "what is this set to?", so an unset value has to be
     * distinguishable from a set one. null and false both rendered as an empty cell before.
     */
    private function formatValue(mixed $value): string
    {
        return match (true) {
            null === $value => '',
            is_bool($value) => $value ? 'true' : 'false',
            [] === $value => '[]',
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => '<' . get_debug_type($value) . '>',
        };
    }
}

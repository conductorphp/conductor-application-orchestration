<?php

namespace ConductorAppOrchestration;

use Amp\Loop;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorAppOrchestration\Deploy\DeploymentState;
use ConductorAppOrchestration\Exception\PlanPathNotEmptyException;
use ConductorAppOrchestration\MaintenanceStrategy\MaintenanceStrategyAwareInterface;
use ConductorAppOrchestration\MaintenanceStrategy\MaintenanceStrategyInterface;
use ConductorCore\Database\DatabaseAdapterManager;
use ConductorCore\Database\DatabaseAdapterManagerAwareInterface;
use ConductorCore\Database\DatabaseImportExportAdapterManager;
use ConductorCore\Database\DatabaseImportExportAdapterManagerAwareInterface;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use ConductorCore\Repository\RepositoryAdapterAwareInterface;
use ConductorCore\Repository\RepositoryAdapterInterface;
use ConductorCore\Shell\Adapter\ShellAdapterAwareInterface;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
use FilesystemIterator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class PlanRunner implements LoggerAwareInterface
{
    /**
     * @var ApplicationConfig
     */
    private $applicationConfig;
    /**
     * @var RepositoryAdapterInterface
     */
    private $repositoryAdapter;
    /**
     * @var ShellAdapterInterface
     */
    private $shellAdapter;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var MaintenanceStrategyInterface
     */
    private $maintenanceStrategy;
    /**
     * @var DatabaseAdapterManager
     */
    private $databaseAdapterManager;
    /**
     * @var DatabaseImportExportAdapterManager
     */
    private $databaseImportExportAdapterManager;
    /**
     * @var ApplicationSkeletonDeployer
     */
    private $applicationSkeletonDeployer;
    /**
     * @var ApplicationCodeDeployer
     */
    private $applicationCodeInstaller;
    /**
     * @var ApplicationAssetDeployer
     */
    private $applicationAssetDeployer;
    /**
     * @var ApplicationDatabaseDeployer
     */
    private $applicationDatabaseDeployer;
    /**
     * @var DeploymentState
     */
    private $deploymentState;
    /**
     * @var int
     */
    private $diskSpaceErrorThreshold;
    /**
     * @var int
     */
    private $diskSpaceWarningThreshold;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var array
     */
    private $plans;
    /**
     * @var string
     */
    private $stepInterface;
    /**
     * @var string
     */
    private $planPath;

    /**
     * PlanRunner constructor.
     *
     * @param ApplicationConfig                  $applicationConfig
     * @param RepositoryAdapterInterface         $repositoryAdapter
     * @param ShellAdapterInterface              $shellAdapter
     * @param MountManager                       $mountManager
     * @param MaintenanceStrategyInterface       $maintenanceStrategy
     * @param DatabaseAdapterManager             $databaseAdapterManager
     * @param DatabaseImportExportAdapterManager $databaseImportExportAdapterManager
     * @param ApplicationSkeletonDeployer        $applicationSkeletonDeployer
     * @param ApplicationCodeDeployer            $applicationCodeInstaller
     * @param ApplicationAssetDeployer           $applicationAssetDeployer
     * @param ApplicationDatabaseDeployer        $applicationDatabaseDeployer
     * @param int                                $diskSpaceErrorThreshold
     * @param int                                $diskSpaceWarningThreshold
     * @param LoggerInterface                    $logger
     */
    public function __construct(
        ApplicationConfig $applicationConfig,
        RepositoryAdapterInterface $repositoryAdapter,
        ShellAdapterInterface $shellAdapter,
        MountManager $mountManager,
        MaintenanceStrategyInterface $maintenanceStrategy,
        DatabaseAdapterManager $databaseAdapterManager,
        DatabaseImportExportAdapterManager $databaseImportExportAdapterManager,
        ApplicationSkeletonDeployer $applicationSkeletonDeployer,
        ApplicationCodeDeployer $applicationCodeInstaller,
        ApplicationAssetDeployer $applicationAssetDeployer,
        ApplicationDatabaseDeployer $applicationDatabaseDeployer,
        DeploymentState $deploymentState,
        int $diskSpaceErrorThreshold = 52428800,
        int $diskSpaceWarningThreshold = 104857600,
        LoggerInterface $logger
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->repositoryAdapter = $repositoryAdapter;
        $this->shellAdapter = $shellAdapter;
        $this->mountManager = $mountManager;
        $this->maintenanceStrategy = $maintenanceStrategy;
        $this->databaseAdapterManager = $databaseAdapterManager;
        $this->databaseImportExportAdapterManager = $databaseImportExportAdapterManager;
        $this->applicationSkeletonDeployer = $applicationSkeletonDeployer;
        $this->applicationCodeInstaller = $applicationCodeInstaller;
        $this->applicationAssetDeployer = $applicationAssetDeployer;
        $this->applicationDatabaseDeployer = $applicationDatabaseDeployer;
        $this->deploymentState = $deploymentState;
        $this->diskSpaceErrorThreshold = $diskSpaceErrorThreshold;
        $this->diskSpaceWarningThreshold = $diskSpaceWarningThreshold;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @todo Deal with user ctrl+c input and clear working directory before exiting
     * @see  http://php.net/manual/en/function.pcntl-signal.php
     *
     * @param string $planName
     * @param array  $conditions
     * @param array  $stepArguments
     * @param bool   $clean
     * @param bool   $rollback
     *
     * @throws \Exception
     */
    public function runPlan(
        string $planName,
        array $conditions,
        array $stepArguments,
        $clean = false,
        $rollback = false
    ): void {
        $origWorkingDirectory = getcwd();
        if (is_null($this->planPath)) {
            $this->planPath = getcwd();
        }

        $metDependencies = [];
        if (!empty($conditions['assets']) || $this->deploymentState->assetsDeployed()) {
            $metDependencies[] = 'assets';
        }

        if (!empty($conditions['code']) || $this->deploymentState->codeDeployed()) {
            $metDependencies[] = 'code';
        }

        if (!empty($conditions['databases']) || $this->deploymentState->databasesDeployed()) {
            $metDependencies[] = 'databases';
        }

        try {
            $plan = $this->getPlan($planName);
            $this->preparePlanPath($plan);

            if ($rollback) {
                $rollbackPreflightSteps = $plan->getRollbackSteps();
                $rollbackSteps = $plan->getRollbackSteps();
                if (!$rollbackSteps) {
                    throw new Exception\RuntimeException(
                        'Rollback requested but plan "' . $planName . '" does not '
                        . 'include any rollback steps.'
                    );
                }

                if ($rollbackPreflightSteps) {
                    $this->logger->info(sprintf('Plan: %s (rollback_preflight)', $planName));
                    foreach ($rollbackPreflightSteps as $name => $step) {
                        $this->runStep($name, $step, $conditions, $metDependencies, $stepArguments);
                    }
                }

                $this->logger->info(sprintf('Plan: %s (rollback)', $planName));
                foreach ($rollbackSteps as $name => $step) {
                    $this->runStep($name, $step, $conditions, $metDependencies, $stepArguments);
                }
            } else {
                $preflightSteps = $plan->getPreflightSteps();
                $steps = $plan->getSteps();
                if (!$steps) {
                    throw new Exception\RuntimeException(
                        'Plan "' . $planName . '" does not include any steps.'
                    );
                }

                if ($preflightSteps) {
                    $this->logger->info(sprintf('Plan: %s (preflight)', $planName));
                    foreach ($preflightSteps as $name => $step) {
                        $this->runStep($name, $step, $conditions, $metDependencies, $stepArguments);
                    }
                }

                $cleanSteps = $plan->getCleanSteps();
                if ($clean && !empty($cleanSteps)) {
                    $this->logger->info(sprintf('Plan: %s (cleanup)', $planName));
                    foreach ($cleanSteps as $name => $step) {
                        $this->runStep($name, $step, $conditions, $metDependencies, $stepArguments);
                    }
                }

                $this->logger->info(sprintf('Plan: %s', $planName));
                foreach ($plan->getSteps() as $name => $step) {
                    $this->runStep($name, $step, $conditions, $metDependencies, $stepArguments);
                }
            }

            if (!$plan->runInAppRoot()) {
                $this->clearPlanPath();
                chdir($origWorkingDirectory);
            }
        } catch (\Exception $e) {
            $this->logger->error('An error occurred running plan "' . $planName . '".');
            if (!$e instanceof PlanPathNotEmptyException && !(isset($plan) && $plan->runInAppRoot())) {
                $this->clearPlanPath();
            }
            chdir($origWorkingDirectory);
            throw $e;
        }
    }

    /**
     * @param array $plans
     */
    public function setPlans(array $plans): void
    {
        $this->plans = $plans;
    }

    /**
     * @param string $className
     */
    public function setStepInterface(string $className): void
    {
        $this->stepInterface = $className;
    }

    /**
     * @param string $name
     *
     * @return Plan
     */
    private function getPlan(string $name): Plan
    {
        if (empty($this->plans[$name])) {
            throw new Exception\DomainException(
                sprintf(
                    'Invalid plan "%s" specified.',
                    $name
                )
            );
        }

        return new Plan($name, $this->plans[$name], $this->stepInterface);
    }

    /**
     * @param Plan $plan
     */
    private function preparePlanPath(Plan $plan): void
    {
        if ($plan->runInAppRoot()) {
            $this->planPath = $this->applicationConfig->getAppRoot();
            return;
        }

        $this->logger->info('Preparing path "' . $this->planPath . '".');
        if (!file_exists($this->planPath)) {
            mkdir($this->planPath, 0777, true);
        }

        if (!(is_dir($this->planPath) && is_writable($this->planPath))) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Path "%s" is not a writable directory.',
                    $this->planPath
                )
            );
        }

        $isEmpty = !(new FilesystemIterator($this->planPath))->valid();
        if (!$isEmpty) {
            throw new Exception\PlanPathNotEmptyException(
                sprintf(
                    'Path "%s" is not empty. Ensure path is empty, then run this command again.',
                    $this->planPath
                )
            );
        }

        $freeDiskSpace = disk_free_space($this->planPath);
        if ($freeDiskSpace <= $this->diskSpaceErrorThreshold) {
            throw new Exception\RuntimeException(
                sprintf(
                    'Less than %sB of space left in path "%s". Aborting.',
                    $this->diskSpaceErrorThreshold,
                    $this->planPath
                )
            );
        }

        if ($freeDiskSpace <= $this->diskSpaceWarningThreshold) {
            $this->logger->warning(
                sprintf(
                    'Less than %sB of space left in path "%s". Aborting.',
                    $this->diskSpaceWarningThreshold,
                    $this->planPath
                )
            );
        }
    }

    private function clearPlanPath(): void
    {
        $this->logger->info('Clearing plan path.');
        $command = 'find ' . escapeshellarg($this->planPath) . ' -mindepth 1 -maxdepth 1 -exec rm -rf {} \;';
        $this->shellAdapter->runShellCommand($command);
    }

    /**
     * @param string $name
     * @param array  $step
     * @param array  $conditions
     * @param array  $metDependencies
     * @param array  $stepArguments
     */
    private function runStep(
        string $name,
        array $step,
        array $conditions,
        array $metDependencies,
        array $stepArguments
    ): void {
        // If array, run commands in parallel
        if (!empty($step['steps'])) {
            foreach ($step['steps'] as $parallelName => $parallelStep) {
                Loop::delay(
                    0,
                    function () use ($parallelName, $parallelStep, $conditions, $metDependencies, $stepArguments) {
                        $this->runStep($parallelName, $parallelStep, $conditions, $metDependencies, $stepArguments);
                    }
                );
            }

            Loop::run();
            return;
        }

        if (!empty($step['conditions'])) {
            if (!array_intersect($conditions, $step['conditions'])) {
                $this->logger->debug(
                    sprintf(
                        'Step: %s - skipped because run %s [%s] not met.',
                        $name,
                        (1 == count($step['conditions'])) ? 'condition' : 'conditions',
                        implode(', ', $step['conditions'])
                    )
                );
                return;
            }
        }

        if (!empty($step['depends'])) {
            if (!array_intersect($metDependencies, $step['depends'])) {
                $this->logger->debug(
                    sprintf(
                        'Step: %s - skipped because %s [%s] not deployed.',
                        $name,
                        (1 == count($step['depends'])) ? 'dependency' : 'dependencies',
                        implode(', ', $step['depends'])
                    )
                );
                return;
            }
        }

        $this->logger->info("Step: $name");
        if (!empty($step['comment'])) {
            $this->logger->debug($step['comment']);
        }
        $commandWorkingDirectory = !empty($step['run_in_code_root'])
            ? $this->applicationConfig->getCodePath()
            : $this->planPath;
        if (!empty($step['command'])) {
            $environmentVariables = array_replace(
                getenv(),
                $stepArguments,
                $step['environment_variables'] ?? []
            );

            $stringEnvironmentVariables = [];
            foreach ($environmentVariables as $key => $value) {
                if (is_string($value)) {
                    $stringEnvironmentVariables[$key] = $value;
                }
            }

            $output = $this->shellAdapter->runShellCommand(
                $step['command'],
                $commandWorkingDirectory,
                $stringEnvironmentVariables,
                $step['run_priority'] ?? ShellAdapterInterface::PRIORITY_NORMAL,
                $step['options'] ?? null
            );
        } elseif (!empty($step['callable'])) {
            chdir($commandWorkingDirectory);
            $output = call_user_func_array($step['callable'], $step['arguments'] ?? []);
        } else {
            chdir($commandWorkingDirectory);
            $stepObject = new $step['class']();
            if ($stepObject instanceof LoggerAwareInterface) {
                $stepObject->setLogger($this->logger);
            }

            if ($stepObject instanceof ApplicationConfigAwareInterface) {
                $stepObject->setApplicationConfig($this->applicationConfig);
            }

            if ($stepObject instanceof RepositoryAdapterAwareInterface) {
                $stepObject->setRepositoryAdapter($this->repositoryAdapter);
            }

            if ($stepObject instanceof ShellAdapterAwareInterface) {
                $stepObject->setShellAdapter($this->shellAdapter);
            }

            if ($stepObject instanceof MountManagerAwareInterface) {
                $stepObject->setMountManager($this->mountManager);
            }

            if ($stepObject instanceof MaintenanceStrategyAwareInterface) {
                $stepObject->setMaintenanceStrategy($this->maintenanceStrategy);
            }

            if ($stepObject instanceof DatabaseAdapterManagerAwareInterface) {
                $stepObject->setDatabaseAdapterManager($this->databaseAdapterManager);
            }

            if ($stepObject instanceof DatabaseImportExportAdapterManagerAwareInterface) {
                $stepObject->setDatabaseImportExportAdapterManager($this->databaseImportExportAdapterManager);
            }

            if ($stepObject instanceof ApplicationSkeletonDeployerAwareInterface) {
                $stepObject->setApplicationSkeletonDeployer($this->applicationSkeletonDeployer);
            }

            if ($stepObject instanceof ApplicationCodeDeployerAwareInterface) {
                $stepObject->setApplicationCodeDeployer($this->applicationCodeInstaller);
            }

            if ($stepObject instanceof ApplicationAssetDeployerAwareInterface) {
                $stepObject->setApplicationAssetDeployer($this->applicationAssetDeployer);
            }

            if ($stepObject instanceof ApplicationDatabaseDeployerAwareInterface) {
                $stepObject->setApplicationDatabaseDeployer($this->applicationDatabaseDeployer);
            }

            $output = call_user_func_array(
                [$stepObject, 'run'],
                array_merge($stepArguments, ['options' => $step['options'] ?? []])
            );
        }

        if (!empty($output)) {
            $output = trim($output);
            // If output is multi-line, start the output on a new line
            if (false !== strpos($output, "\n")) {
                $output = "\n$output";
            }
            $this->logger->debug('Step "' . $name . '" output: ' . $output);
        }
    }

    /**
     * @param string $planPath
     */
    public function setPlanPath(string $planPath)
    {
        $this->planPath = $planPath;
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

}

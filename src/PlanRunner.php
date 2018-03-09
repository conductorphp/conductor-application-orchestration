<?php

namespace ConductorAppOrchestration;

use Amp\Loop;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Config\ApplicationConfigAwareInterface;
use ConductorCore\Filesystem\MountManager\MountManager;
use ConductorCore\Filesystem\MountManager\MountManagerAwareInterface;
use ConductorCore\Shell\Adapter\ShellAdapterAwareInterface;
use ConductorCore\Shell\Adapter\ShellAdapterInterface;
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
     * @var ShellAdapterInterface
     */
    private $shellAdapter;
    /**
     * @var MountManager
     */
    private $mountManager;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var string
     */
    private $expectedClassInterface;

    public function __construct(
        ApplicationConfig $applicationConfig,
        ShellAdapterInterface $shellAdapter,
        MountManager $mountManager,
        LoggerInterface $logger
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->shellAdapter = $shellAdapter;
        $this->mountManager = $mountManager;
        if (is_null($logger)) {
            $logger = new NullLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @param array $plan
     * @param array $options
     */
    public function runPlan(array $plan, array $options = []): void
    {
        foreach ($plan as $name => $step) {
            $this->runStep($name, $step, $options);
        }
    }

    private function runStep(string $name, array $step, array $options = []): void
    {
        // If array, run commands in parallel
        if (!empty($step['steps'])) {
            foreach ($step['steps'] as $parallelName => $parallelStep) {
                Loop::delay(
                    0,
                    function () use ($parallelName, $parallelStep, $options) {
                        $this->runStep($parallelName, $parallelStep, $options);
                    }
                );
            }

            Loop::run();
            return;
        }

        $this->logger->info("Running Step \"$name\".");
        if (!empty($step['command'])) {
            $environmentVariables = array_replace(
                getenv(),
                $options,
                $step['environment_variables'] ?? []
            );

            $this->shellAdapter->runShellCommand(
                $step['command'],
                $step['working_directory'] ?? $this->applicationConfig->getCodePath(),
                $environmentVariables,
                $step['run_priority'] ?? ShellAdapterInterface::PRIORITY_NORMAL,
                $step['options'] ?? null
            );
        } elseif (!empty($step['callable'])) {
            call_user_func_array($step['callable'], $step['arguments'] ?? []);
        } else {
            $stepObject = new $step['class']();
            if ($stepObject instanceof LoggerAwareInterface) {
                $stepObject->setLogger($this->logger);
            }

            if ($stepObject instanceof ApplicationConfigAwareInterface) {
                $stepObject->setApplicationConfig($this->applicationConfig);
            }

            if ($stepObject instanceof ShellAdapterAwareInterface) {
                $stepObject->setShellAdapter($this->shellAdapter);
            }

            if ($stepObject instanceof MountManagerAwareInterface) {
                $stepObject->setMountManager($this->mountManager);
            }

            call_user_func_array(
                [$stepObject, 'run'],
                array_merge($options, ['options' => $step['options'] ?? []])
            );
        }
    }

    /**
     * @param array $plan
     *
     * @return array
     *
     */
    public function validateAndNormalizePlan(array $plan): array
    {
        $normalizedPlan = [];
        foreach ($plan as $name => $step) {
            [$name, $step] = $this->validateAndNormalizeStep($name, $step);
            if (!is_null($name)) {
                $normalizedPlan[$name] = $step;
            } else {
                $normalizedPlan[] = $step;
            }
        }

        return $normalizedPlan;
    }

    /**
     * @param string $name
     * @param        $step
     *
     * @return array
     */
    private function validateAndNormalizeStep(string $name, $step): array
    {
        static $depth = 0;
        if (!is_array($step)) {
            if (is_string($step) && class_exists($step)) {
                $step = [
                    'class' => $step,
                ];
            } elseif (is_callable($step)) {
                $step = [
                    'callable' => $step,
                ];
            } else {
                $step = [
                    'command' => $step,
                ];
            }
        } else {
            $numMatchedStepTypes = count(array_intersect(['class', 'command', 'callable', 'steps'], array_keys($step)));
            if (0 == $numMatchedStepTypes) {
                if (0 == $depth) {
                    $depth++;
                    // Assume this is an array of commands to be run in parallel
                    $parallelSteps = [];
                    foreach ($step as $parallelName => $parallelStep) {
                        [$parallelName, $parallelStep] = $this->validateAndNormalizeStep(
                            $parallelName,
                            $parallelStep
                        );
                        if (!is_null($parallelName)) {
                            $parallelSteps[$parallelName] = $parallelStep;
                        } else {
                            $parallelSteps[] = $parallelStep;
                        }
                    }
                    $step = [
                        'steps' => $parallelSteps,
                    ];
                    $depth--;
                } else {
                    // Do not allow multiple levels of steps
                    throw new Exception\RuntimeException(
                        'Step "' . $name . '" must include one of the keys "class", "callable", or "command".'
                    );
                }
            } elseif ($numMatchedStepTypes > 1) {
                throw new Exception\RuntimeException(
                    'Step "' . $name
                    . '" may only include one of the keys "class", "callable", "command", or "steps".'
                );
            }
        }

        // @todo Maybe remove this if we move to Yaml config
        if (!empty($step['callable']) && !is_string($step['callable'])) {
            throw new Exception\RuntimeException(
                'Step "' . $name . '" may not be defined as a closure since it cannot be cached. Create a '
                . 'class with a static method instead and define this step as MyClass::myMethod'
            );
        }

        if (!empty($step['class']) && !in_array($this->expectedClassInterface, class_implements($step['class']))) {
            throw new Exception\RuntimeException(
                'Step "' . $name . '" class must implement ' . $this->expectedClassInterface . '.'
            );
        }


        // @todo Should we force a name to be set instead?
        if (empty($name) || is_numeric($name)) {
            // @todo Need unique ID for steps without a name or does null work?
            $name = $step['class'] ?? $step['command'] ?? $step['callable'] ?? null;
        }

        return [$name, $step];
    }

    /**
     * @inheritdoc
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setExpectedClassInterface(string $className): void
    {
        $this->expectedClassInterface = $className;
    }

}

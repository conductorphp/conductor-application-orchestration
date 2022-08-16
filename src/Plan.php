<?php

namespace ConductorAppOrchestration;

class Plan
{
    private string $name;
    private string $stepInterface;
    private array $preflightSteps;
    private array $cleanSteps;
    private array $steps;
    private array $rollbackPreflightSteps;
    private array $rollbackSteps;

    public function __construct(string $name, array $plan, string $stepInterface)
    {
        $this->name = $name;
        $this->stepInterface = $stepInterface;
        $plan = $this->validateAndNormalize($plan);
        $this->preflightSteps = $plan['preflight_steps'];
        $this->cleanSteps = $plan['clean_steps'];
        $this->steps = $plan['steps'];
        $this->rollbackPreflightSteps = $plan['rollback_preflight_steps'];
        $this->rollbackSteps = $plan['rollback_steps'];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPreflightSteps(): array
    {
        return $this->preflightSteps;
    }

    public function getCleanSteps(): array
    {
        return $this->cleanSteps;
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getRollbackPreflightSteps(): array
    {
        return $this->rollbackPreflightSteps;
    }

    public function getRollbackSteps(): array
    {
        return $this->rollbackSteps;
    }

    private function validateAndNormalize(array $plan): array
    {
        if (empty($plan['steps'])) {
            throw new Exception\RuntimeException('Key "steps" must be set in plan "' . $this->name . '".');
        }

        $stepTypes = ['preflight_steps', 'clean_steps', 'steps', 'rollback_preflight_steps', 'rollback_steps'];
        $normalizedPlan = $plan;
        foreach ($stepTypes as $stepType) {
            $normalizedPlan[$stepType] = [];
            if (!empty($plan[$stepType])) {
                foreach ($plan[$stepType] as $name => $step) {
                    [$name, $step] = $this->validateAndNormalizeStep($name, $step);
                    if (!is_null($name)) {
                        $normalizedPlan[$stepType][$name] = $step;
                    } else {
                        $normalizedPlan[$stepType][] = $step;
                    }
                }
            }
        }

        return $normalizedPlan;
    }

    private function validateAndNormalizeStep(string $name, array|string $step): array
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
            if (0 === $numMatchedStepTypes) {
                if (0 === $depth) {
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

        if (!empty($step['class']) && !in_array($this->stepInterface, class_implements($step['class']))) {
            throw new Exception\RuntimeException(
                'Step "' . $name . '" class must implement ' . $this->stepInterface . '.'
            );
        }


        // @todo Should we force a name to be set instead?
        if (empty($name) || is_numeric($name)) {
            // @todo Need unique ID for steps without a name or does null work?
            $name = $step['class'] ?? $step['command'] ?? $step['callable'] ?? null;
        }

        return [$name, $step];
    }
}

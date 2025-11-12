<?php

namespace ConductorAppOrchestration\Build;

use ConductorAppOrchestration\Build\Command\BuildCommandInterface;
use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\PlanRunner;
use Exception;
use Psr\Log\LoggerInterface;

class ApplicationBuilder
{
    private ApplicationConfig $applicationConfig;
    private PlanRunner $planRunner;
    private string $planPath;

    public function __construct(
        ApplicationConfig $applicationConfig,
        PlanRunner        $planRunner,
        string            $planPath = '/tmp/.conductor/build',
    ) {
        $this->applicationConfig = $applicationConfig;
        $this->planRunner = $planRunner;
        $this->planPath = $planPath;
    }

    /**
     * @throws Exception
     */
    public function build(
        string $buildPlan,
        string $repoReference,
        string $buildId,
        string $savePath
    ): void {
        $buildPlans = $this->applicationConfig->getBuildConfig()->getPlans();
        $this->planRunner->setPlans($buildPlans);
        $this->planRunner->setPlanPath($this->planPath);
        $this->planRunner->setStepInterface(BuildCommandInterface::class);
        $this->planRunner->runPlan(
            $buildPlan,
            [],
            [
                'repoReference' => $repoReference,
                'buildId' => $buildId,
                'savePath' => $savePath,
            ],
        );
    }

    public function setPlanPath(string $planPath): void
    {
        $this->planPath = $planPath;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->planRunner->setLogger($logger);
    }

}

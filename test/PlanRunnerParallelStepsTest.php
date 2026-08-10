<?php

namespace ConductorAppOrchestrationTest;

use ConductorAppOrchestration\PlanRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use Stringable;

/**
 * Covers the parallel-step branch of PlanRunner::runStep(), which schedules each
 * child step on the event loop and merges what they provide once the loop drains.
 *
 * PlanRunner takes twelve collaborators, so the instance is built without its
 * constructor and given only the logger. Child steps declare a condition that is
 * not met, which makes each one return early through the logger without reaching
 * any collaborator — enough to prove every scheduled callback actually ran.
 */
class PlanRunnerParallelStepsTest extends TestCase
{
    private PlanRunner $planRunner;

    /** @var AbstractLogger&object{messages: string[]} */
    private $logger;

    public function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $reflection = new ReflectionClass(PlanRunner::class);
        $this->planRunner = $reflection->newInstanceWithoutConstructor();

        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setValue($this->planRunner, $this->logger);
    }

    public function testEveryParallelChildStepRuns(): void
    {
        $step = [
            'steps' => [
                'alpha' => ['conditions' => ['never']],
                'beta' => ['conditions' => ['never']],
                'gamma' => ['conditions' => ['never']],
            ],
        ];

        $provided = $this->runStep('parent', $step);

        $this->assertSame([], $provided);
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $this->assertTrue(
                (bool) array_filter($this->logger->messages, static fn(string $m): bool => str_contains($m, $name)),
                sprintf('Parallel step "%s" did not run.', $name)
            );
        }
    }

    public function testParallelStepsWithNoChildrenReturnNothing(): void
    {
        $this->assertSame([], $this->runStep('parent', ['steps' => ['only' => ['conditions' => ['never']]]]));
    }

    /**
     * The event loop must be reusable — a second plan run schedules a fresh set of
     * callbacks after the first run has already drained it.
     */
    public function testConsecutiveParallelStepsRun(): void
    {
        $this->runStep('first', ['steps' => ['alpha' => ['conditions' => ['never']]]]);
        $this->runStep('second', ['steps' => ['beta' => ['conditions' => ['never']]]]);

        $joined = implode("\n", $this->logger->messages);
        $this->assertStringContainsString('alpha', $joined);
        $this->assertStringContainsString('beta', $joined);
    }

    private function runStep(string $name, array $step): array
    {
        $method = (new ReflectionClass(PlanRunner::class))->getMethod('runStep');

        return $method->invoke($this->planRunner, $name, $step, [], [], []);
    }
}

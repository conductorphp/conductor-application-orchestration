<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Console;

use ConductorAppOrchestration\Console\AppDeployCommand;
use ConductorAppOrchestration\Deploy\ApplicationDeployer;
use ConductorAppOrchestrationTest\BuildsApplicationConfigTrait;
use ConductorCore\Filesystem\MountManager\MountManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:deploy --skeleton` passed its options to deploySkeleton() one slot early, so --force ran the
 * clean steps and --clean was dropped (CTAP-2585).
 */
final class AppDeployCommandSkeletonTest extends TestCase
{
    use BuildsApplicationConfigTrait;

    /** @return iterable<string, array{array<string, bool>, bool, bool}> */
    public static function options(): iterable
    {
        yield '--force does not clean' => [['--force' => true], false, true];
        yield '--clean cleans' => [['--clean' => true, '--force' => true], true, true];
    }

    #[Test]
    #[DataProvider('options')]
    public function skeletonPassesCleanAndForceThrough(array $options, bool $clean, bool $force): void
    {
        $deployer = $this->createMock(ApplicationDeployer::class);
        $deployer->expects(self::once())
            ->method('deploySkeleton')
            ->with('development', null, $clean, $force);
        $deployer->expects(self::never())->method('deploy');

        $config = $this->applicationConfig([
            'deploy' => ['default_plan' => 'development', 'plans' => ['development' => ['steps' => ['a' => 'true']]]],
        ]);
        $mountManager = $this->createStub(MountManager::class);
        $mountManager->method('getFilesystemPrefixes')->willReturn(['local']);

        $tester = new CommandTester(new AppDeployCommand($config, $deployer, $mountManager));
        $tester->execute(['--skeleton' => true, '--working-dir' => sys_get_temp_dir() . '/ctap-2585'] + $options);

        self::assertSame(0, $tester->getStatusCode());
    }
}

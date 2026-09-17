<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Deploy;

use ConductorAppOrchestration\Config\ApplicationConfig;
use ConductorAppOrchestration\Deploy\ApplicationSkeletonDeployer;
use ConductorAppOrchestration\Exception\RuntimeException;
use ConductorAppOrchestration\FileLayoutInterface;
use ConductorCore\Shell\Adapter\LocalShellAdapter;
use PHPUnit\Framework\TestCase;

use function chmod;
use function dirname;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function is_dir;
use function is_file;
use function mkdir;
use function posix_geteuid;
use function sys_get_temp_dir;
use function uniqid;

/**
 * CTAP-1752. The app root only has to be writable when the deploy will write into it.
 *
 * A Webscale image bakes the code in read-only (root:root 755) and hands the deploy identity only
 * the skeleton targets. The default layout never writes to the app root itself, so that is a valid
 * deploy target; blue/green still creates local/, shared/, releases/ and `current` there and still
 * has to refuse a read-only root.
 *
 * Root ignores directory modes (`is_writable` on a 0555 directory is true), so the read-only cases
 * skip under euid 0 — which is how CI's php:*-cli images run.
 */
class ApplicationSkeletonDeployerAppRootTest extends TestCase
{
    private string $base;
    private string $appRoot;
    private string $sourcePath;

    protected function setUp(): void
    {
        $this->base       = sys_get_temp_dir() . '/conductor-app-root-' . uniqid();
        $this->appRoot    = $this->base . '/app';
        $this->sourcePath = $this->base . '/files';
        mkdir($this->sourcePath, 0700, true);
        file_put_contents($this->sourcePath . '/rendered.twig', "<?php return '{{ data.env }}';\n");
    }

    protected function tearDown(): void
    {
        // Contents of a read-only directory cannot be removed until it is writable again.
        (new LocalShellAdapter())->runShellCommand(
            'chmod -R u+rwx ' . escapeshellarg($this->base) . ' && rm -rf ' . escapeshellarg($this->base)
        );
    }

    private function skipUnlessDirectoryModesAreEnforced(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root bypasses directory modes; a read-only app root cannot be simulated.');
        }
    }

    private function config(string $fileLayout): ApplicationConfig
    {
        return new ApplicationConfig([
            'app_name'          => 'Test App',
            'app_root'          => $this->appRoot,
            'repo_url'          => 'git@example.test:test/app.git',
            'environment'       => 'production',
            'file_layout'       => $fileLayout,
            'source_file_paths' => [$this->sourcePath],
            'skeleton'          => [
                'files' => [
                    'config/autoload/rendered.php' => [
                        'location'      => 'code',
                        'source'        => 'rendered.twig',
                        'mode'          => '0640',
                        'template_vars' => ['data' => ['env' => 'production']],
                    ],
                ],
            ],
        ]);
    }

    private function deployer(string $fileLayout): ApplicationSkeletonDeployer
    {
        return new ApplicationSkeletonDeployer($this->config($fileLayout), new LocalShellAdapter());
    }

    /** The Webscale shape: read-only code root, only the skeleton target directory is writable. */
    public function testDefaultLayoutDeploysIntoAReadOnlyAppRootWhenTheSkeletonTargetIsWritable(): void
    {
        $this->skipUnlessDirectoryModesAreEnforced();

        mkdir($this->appRoot . '/config/autoload', 0700, true);
        chmod($this->appRoot . '/config', 0555);
        chmod($this->appRoot, 0555);

        $this->deployer(FileLayoutInterface::STRATEGY_DEFAULT)->deploySkeleton();

        $rendered = $this->appRoot . '/config/autoload/rendered.php';
        $this->assertTrue(is_file($rendered));
        $this->assertSame("<?php return 'production';\n", file_get_contents($rendered));
    }

    /** The precise failure is still the skeleton target, not the app root. */
    public function testDefaultLayoutFailsOnTheUnwritableSkeletonTargetNotTheAppRoot(): void
    {
        $this->skipUnlessDirectoryModesAreEnforced();

        mkdir($this->appRoot . '/config/autoload', 0700, true);
        chmod($this->appRoot . '/config/autoload', 0555);
        chmod($this->appRoot . '/config', 0555);
        chmod($this->appRoot, 0555);

        $deployer = $this->deployer(FileLayoutInterface::STRATEGY_DEFAULT);

        // The layout itself is fine; the write into config/autoload is what fails.
        $deployer->prepareFileLayout();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Skeleton file \"{$this->appRoot}/config/autoload/rendered.php\" could not be written.");
        // The refused write also emits a PHP warning; the exception is the assertion, so keep it quiet.
        @$deployer->installAppFiles();
    }

    public function testBlueGreenLayoutStillRefusesAReadOnlyAppRoot(): void
    {
        $this->skipUnlessDirectoryModesAreEnforced();

        mkdir($this->appRoot, 0555, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Project root \"{$this->appRoot}\" is not writable.");

        $this->deployer(FileLayoutInterface::STRATEGY_BLUE_GREEN)->prepareFileLayout();
    }

    public function testBlueGreenLayoutCreatesItsDirectoriesInAWritableAppRoot(): void
    {
        mkdir($this->appRoot, 0700, true);

        $this->deployer(FileLayoutInterface::STRATEGY_BLUE_GREEN)->prepareFileLayout('build-1');

        $this->assertTrue(is_dir($this->appRoot . '/' . FileLayoutInterface::PATH_LOCAL));
        $this->assertTrue(is_dir($this->appRoot . '/' . FileLayoutInterface::PATH_SHARED));
        $this->assertTrue(is_dir($this->appRoot . '/' . FileLayoutInterface::PATH_BUILDS . '/build-1'));
    }

    public function testAMissingAppRootIsCreatedWhenItsParentIsWritable(): void
    {
        $this->assertFalse(is_dir($this->appRoot));

        $this->deployer(FileLayoutInterface::STRATEGY_DEFAULT)->prepareFileLayout();

        $this->assertTrue(is_dir($this->appRoot));
    }

    public function testAMissingAppRootUnderAReadOnlyParentIsRefused(): void
    {
        $this->skipUnlessDirectoryModesAreEnforced();

        chmod(dirname($this->appRoot), 0555);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Project root \"{$this->appRoot}\" does not exist and its parent directory is not writable.");

        $this->deployer(FileLayoutInterface::STRATEGY_DEFAULT)->prepareFileLayout();
    }

    public function testAFileAtTheAppRootPathIsRefused(): void
    {
        file_put_contents($this->appRoot, '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Project root \"{$this->appRoot}\" is not a directory.");

        $this->deployer(FileLayoutInterface::STRATEGY_DEFAULT)->prepareFileLayout();
    }
}

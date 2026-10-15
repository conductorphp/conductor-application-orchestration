<?php

declare(strict_types=1);

namespace ConductorAppOrchestrationTest\Snapshot;

use ConductorAppOrchestration\Snapshot\Command\DeleteExistingSnapshotCommand;
use ConductorCore\Filesystem\MountManager\MountManager;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\WhitespacePathNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `app:snapshot --replace` deletes the old snapshot, which is a directory. delete() removes a single
 * file, so the replace failed on a local path and left the old files on S3 (CTAP-2586).
 */
final class DeleteExistingSnapshotCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/delete-snapshot-test-' . bin2hex(random_bytes(4));
        foreach (['databases/app.tgz', 'databases/dropped.tgz', 'assets/media/a.jpg'] as $file) {
            $dir = dirname("$this->root/snap/$file");
            is_dir($dir) || mkdir($dir, 0777, true);
            file_put_contents("$this->root/snap/$file", 'old');
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function databasesOnlyDeletesTheDatabasesAndKeepsTheAssets(): void
    {
        $this->deleteSnapshot(includeDatabases: true, includeAssets: false);

        self::assertDirectoryDoesNotExist("$this->root/snap/databases");
        self::assertFileExists("$this->root/snap/assets/media/a.jpg");
    }

    #[Test]
    public function assetsOnlyDeletesTheAssetsAndKeepsTheDatabases(): void
    {
        $this->deleteSnapshot(includeDatabases: false, includeAssets: true);

        self::assertDirectoryDoesNotExist("$this->root/snap/assets");
        self::assertFileExists("$this->root/snap/databases/app.tgz");
    }

    #[Test]
    public function bothDeleteTheWholeSnapshot(): void
    {
        $this->deleteSnapshot(includeDatabases: true, includeAssets: true);

        self::assertDirectoryDoesNotExist("$this->root/snap");
    }

    #[Test]
    public function aSnapshotThatDoesNotExistYetIsNotAnError(): void
    {
        $this->deleteSnapshot(includeDatabases: true, includeAssets: true, snapshotName: 'new');

        self::assertFileExists("$this->root/snap/databases/app.tgz");
    }

    private function deleteSnapshot(bool $includeDatabases, bool $includeAssets, string $snapshotName = 'snap'): void
    {
        $command = new DeleteExistingSnapshotCommand();
        $command->setMountManager(new MountManager(
            ['local' => new Filesystem(new LocalFilesystemAdapter($this->root))],
            new WhitespacePathNormalizer()
        ));
        $command->run($snapshotName, 'local://', $includeDatabases, $includeAssets);
    }
}

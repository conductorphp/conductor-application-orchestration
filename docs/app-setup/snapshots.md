Snapshots
=========

Snapshots can be used for backups or for syncing databases and assets from one environment to another.

A snapshot is taken of an environment and pushed into an external file system like Amazon S3 where another environment 
can pull the snapshot using the app:install, app:refresh-assets, or app:refresh-databases commands. On both ends, a 
snapshot name can be specified.

The snapshot command runs in a way that will not cause a performance degredation because it uses `nice` and `ionice` to 
ensure that it is not contending for disk or cpu resources. This can make it useful for getting a copy of a production 
database under load if you don't have an existing tool like Percona XtraDB Backup. 

To take a snapshot:
```bash
devops app:snapshot
```

The default settings will create a scrubbed snapshot named "$environment-scrubbed", where $environment is the 
environment configured in ~/.devops/app-setup.yaml. The default behavior will also remove things like customer data, 
log data, etc. before pushing the snapshot to the external file system. To disable this, specify `--no-scrub`.

The items that are scrubbed are determined by the `excludes` list for both assets and databases. This is specified in 
the App Setup repository. This tool also comes with built into asset groups and ignored table groups for different 
platforms. For example, to exclude all Magento 1 core tables, you would add "@magento1_core" to your excludes list. 
These built in groups can be found in [config/app-setup-defaults](../../config/app-setup-defaults) for your platform.

We recommend sending non-scrubbed snapshots only to a filesystem which is accessible from the Production environment 
only. Always avoid putting production data into a test environment or a filesystem a test environment has access to. You
should create a Staging environment and treat it with the same security concerns as you would Production if you have a 
need to replication Production into another environment with non-scrubbed data.

Snapshots can also be sent to multiple file systems which must be configured in the App Setup repo. Amazon S3 is 
currently supported, but any file system can be used. We are using [Flysystem](https://flysystem.thephpleague.com/) with 
a custom interface built on top named [FilesystemTransferInterface](../../src/FilesystemTransferInterface.php) which 
allows for transfer between two Flysystem [FilesystemInterface](https://github.com/thephpleague/flysystem/blob/master/src/FilesystemInterface.php)
objects.

A default filesystem must be specified in the App Setup repo. To use a different filesystem, include the `--fileesystem`
argument.

A branch may also be specified when creating a snapshot if using the "branch" `file_layout` in the environment the 
snapshot is being taken from. To do so, a "--branch mybranch" flag. 

### Examples

To take a non-scrubbed snapshot and install it on Staging, run:
```bash
# On Production, run:
devops app:snapshot --no-scrub

# On Staging, run:
devops app:install --snapshot production --reinstall
```

To take a scrubbed snapshot and create a "release-1.2.3" branch on UAT from it:
```bash
# On Production, run:
devops app:snapshot --filesystem test

# On UAT, run:
devops app:install --branch release-1.2.3;
```

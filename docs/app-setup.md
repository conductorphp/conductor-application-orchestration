App Setup
=========
App setup commands prefixed with app: include functionality for application installation, 
configuration, backups, code deployments, maintenance mode, syncing of environments, 
and others.

Initial setup
-------------
In order to use these commands, you must first create a file named configuration
in the following format:

```yaml

---
environment: production
role: web
apps:
  myapp1:
    repo_url: git@bitbucket.org:robofirm/myapp1-app-setup.git
  myapp2:
    repo_url: git@bitbucket.org:robofirm/myapp2-app-setup.git
```

For more information, see [App Setup Configuration](app-setup/configuration.md).

All commands include a `--help` option with more information. You can also type `devops app` to see a full list of
available commands.

Snapshots
---------
Snapshots can be used for backups or for syncing databases and assets from one environment 
to another.

A snapshot is taken of an environment and pushed into an external file system like Amazon
S3 where another environment can pull the snapshot using the app:install, app:refresh-assets,
or app:refresh-databases commands. On both ends, a snapshot name can be specified.

The snapshot command runs in a way that will not cause a performance degredation because it 
uses `nice` and `ionice` to ensure that it is not contending for disk or cpu resources, so 
this can be useful for getting a copy of a production database under load if you don't have 
an existing tool like Percona XtraDB Backup for this. 

Snapshots can be scrubbed, meaning things like customer data, log data, etc. are removed 
before pushing the snapshot to the external file system.

To take a snapshot:
```bash
devops app:snapshot
```

Form more information, see [Snapshots](app-setup/snapshots.md).

Install, Refresh Databases, and Refresh Assets
----------------------------------------------

These commands can be used to install snapshots or to set up the directory structure with all files needed that are not
included in the applications code repository.

To install an application in full, run:
```bash
devops app:install
```

If you have multiple applications on one server, you will be prompted to specify which app or choose to run this for all
applications. You can do so with the `--app myap` flag or the `--all` flag. The app name here is the same app name used
in `configuration`.

```bash
# Install the "myapp" app
devops app:install --app myapp

# Install all apps
devops app:install --all
```

If the application has already been installed, you can specify the `--reinstall` flag to refresh it. You can also refresh
only the file structure without refreshing databases and media by running a command like this:
```bash
devops app:install --reinstall --skeleton
```

There are also `--no-assets` and `--no-databases` flags.

To install from a specific snapshot, use the `--snapshot mysnapshot` argument. If that snapshot lives on a different 
filesystem than the default, you must also specify the `--filesystems myfilesystem` argument, where the filesystem name
is the name as defined in the App Setup repo.

If you only want to install/refresh the database or media, there are commands with a similar set of arguments that are 
built for this.

```bash
# Refresh databases from the "production-scrubbed" snapshot on the default filesystem
devops app:refresh-databases

# Refresh assets from the "mysnapshot" snapshot on the "myfilesystem" filesystem
devops app:refresh-assets --snapshot mysnapshot --filesystem myfilesystem
```

Destroy
-------

Be careful with this one. It's not often needed since the Install command above is built to be able to be run over and 
over again, even if the app is already installed, and ensure the correct state (idempotent).

This will destroy your application, along with its databases and assets:
```bash
devops app:destroy
```

Builds & Deployments
--------------------

The devops tool can create builds, upload them to a remote filesystem, and then deploy them. The build 
process can also be used to prepare a local working directory.

See [Builds & Deployments](app-setup/builds-and-deployments.md) for more information.

Maintenance mode
----------------

This tool can enable, disable, and check the status of your application's maintenance mode and supports multiple methods
of doing so through the [MaintenanceStrategyInterface](../src/App/MaintenanceStrategy/MaintenanceStrategyInterface.php).
A [Magento1FileMaintenanceStrategy](../src/App/MaintenanceStrategy/Magento1FileMaintenanceStrategy.php) is been provided
with this tool along with others for other platforms in the future.

To use the Magento1FileMaintenanceStrategy, specify all web servers for an application environment in the App Setup repo
like this:

```bash
servers:
  - host: 172.31.31.2
  - host: 172.31.31.3
  - host: 172.31.31.4
```

Then, run these commands:
```bash
# Enable maintenance mode
devops app:maintenance:enable

# Disable maintenance mode
devops app:maintenance:disable

# Check if maintenance mode is enabled
devops app:maintenance:status
```

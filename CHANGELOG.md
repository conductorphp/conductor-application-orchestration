[3.1.0](https://github.com/conductorphp/conductor-application-orchestration/compare/3.0.0...3.1.0) (2026-08-10)

### Features
* PHP 8.4.1+ (CTAP-1224) ([8944077](https://github.com/conductorphp/conductor-application-orchestration/commit/89440775496129e58d6b201c7a9219dec3498133))
* revolt/event-loop (CTAP-1223) ([71b60ec](https://github.com/conductorphp/conductor-application-orchestration/commit/71b60ec7367e3d13971e9bdc37e47300bea1372e))

<!--- CHANGELOG SPLIT MARKER -->

[2.0.1](https://github.com/conductorphp/conductor-application-orchestration/compare/2.0.0...2.0.1) (2026-06-25)

### Bug Fixes
* 8.2-8.5 support ([232cc48](https://github.com/conductorphp/conductor-application-orchestration/commit/232cc489e76d58d2ce6c5f4fa292cc61f8e63f15))

<!--- CHANGELOG SPLIT MARKER -->

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

### Added

- Added support for PHP 8.1 and 8.2

### Removed

- Removed support for PHP 8.0 and below

## [1.4.0] - Unreleased

### Added

- Added support for PHP 8.0 and 8.1
- Added logic to allow pushing build directly to build path without tarballing, respecting includes/excludes.

## [1.3.3] - 2022-07-13

### Fixed

- Fixed PHP Constraints. 1.3 no longer works with PHP 7.3

## [1.3.2] - 2022-07-13

### Fixed

- Fixed bug in ensuring directory permissions

## [1.3.1] - 2022-06-20

### Fixed

- Improved error messaging.

## [1.3.0] - 2022-05-16

### Added

- Created top level application/databases configuration for `alias`, `adapter`, and `importexport_adapter`.
- Added `var_export` Twig extension.
- Added a default list of `source_file_paths` for templates in order to support the
  `var-export.php.twig` template.
- Enabled Twig debug mode and added `DebugExtension` to allow for calling of `dump` in templates.

## [1.2.1] - 2022-07-13

### Fixed

- Fixed bug in ensuring directory permissions

## [1.2.0] - 2021-05-20

### Added

- Added sane defaults for `CodeDeploymentStateInterface` and `MaintenanceStrategyInterface` to allow Conductor to work
  for custom PHP apps.

## [1.1.0] - 2021-01-28

### Added

- Added concurrency support for deploy and snapshot commands.

## [1.0.0] - 2021-01-21

### Added

- Added `app:build` command.
- Added `app:config:show` command.
- Added `app:deploy` command.
- Added `app:destroy` command.
- Added `app:maintenance` command.
- Added `app:snapshot` command.
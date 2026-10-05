[4.8.1](https://github.com/conductorphp/conductor-application-orchestration/compare/4.8.0...4.8.1) (2026-10-05)

### Bug Fixes
* runs rollback_preflight_steps, and rollback_steps once (CTAP-2199) ([9dabdee](https://github.com/conductorphp/conductor-application-orchestration/commit/9dabdee290eeb8464f1f1f4a22786c8372ddf4ab))

<!--- CHANGELOG SPLIT MARKER -->

[4.8.0](https://github.com/conductorphp/conductor-application-orchestration/compare/4.7.1...4.8.0) (2026-10-05)

### Features
* run when a plan step fails (CTAP-2197) ([1e42057](https://github.com/conductorphp/conductor-application-orchestration/commit/1e4205707c90735a7381c59368377398d0c34669))

<!--- CHANGELOG SPLIT MARKER -->

[4.7.1](https://github.com/conductorphp/conductor-application-orchestration/compare/4.7.0...4.7.1) (2026-10-03)

### Bug Fixes
* wait step fails fast on an unset variable or a URL it can never reach (CTAP-2150) ([a63098d](https://github.com/conductorphp/conductor-application-orchestration/commit/a63098d2ada2e44e2478b071fcebbea082f03ce5))

<!--- CHANGELOG SPLIT MARKER -->

[4.7.0](https://github.com/conductorphp/conductor-application-orchestration/compare/4.6.1...4.7.0) (2026-10-03)

### Features
* snapshot groups warn; expansion drops duplicates (CTAP-2161) ([5157865](https://github.com/conductorphp/conductor-application-orchestration/commit/51578658ca8e5f05c5766e07123551324a0cfe25))

<!--- CHANGELOG SPLIT MARKER -->

[4.6.1](https://github.com/conductorphp/conductor-application-orchestration/compare/4.6.0...4.6.1) (2026-10-02)


<!--- CHANGELOG SPLIT MARKER -->

[4.6.0](https://github.com/conductorphp/conductor-application-orchestration/compare/4.5.0...4.6.0) (2026-10-02)

### Features
* step wait: polls a URL until ready (CTAP-2145) ([e85c0b9](https://github.com/conductorphp/conductor-application-orchestration/commit/e85c0b912e35e4379445e46db8ac0440f551fb57))

<!--- CHANGELOG SPLIT MARKER -->

[4.5.0](https://github.com/conductorphp/conductor-application-orchestration/compare/4.4.3...4.5.0) (2026-10-02)

### Features
* step notices and app:plans (CTAP-2143) ([f6958cd](https://github.com/conductorphp/conductor-application-orchestration/commit/f6958cd0f3264f8b633e7ef0efdd37e80c0f284f))

<!--- CHANGELOG SPLIT MARKER -->

[4.4.3](https://github.com/conductorphp/conductor-application-orchestration/compare/4.4.2...4.4.3) (2026-09-28)

### Bug Fixes
* a failed command step's output at ERROR (CTAP-2006) ([86ebba5](https://github.com/conductorphp/conductor-application-orchestration/commit/86ebba5e85a93e167ef2c587c3a7d44e6ea5ce49))

<!--- CHANGELOG SPLIT MARKER -->

[4.4.2](https://github.com/conductorphp/conductor-application-orchestration/compare/4.4.1...4.4.2) (2026-09-27)


<!--- CHANGELOG SPLIT MARKER -->

[4.4.1](https://github.com/conductorphp/conductor-application-orchestration/compare/4.4.0...4.4.1) (2026-09-17)

### Bug Fixes
* a writable app root only when the deploy writes there (CTAP-1752) ([bcec4cf](https://github.com/conductorphp/conductor-application-orchestration/commit/bcec4cf46e3b7cf5231b3a308956dbd1dce44ebd))

<!--- CHANGELOG SPLIT MARKER -->

[4.4.0](https://github.com/conductorphp/conductor-application-orchestration/compare/4.3.0...4.4.0) (2026-09-16)

### Features
* conductor/core ^6.0 (CTAP-1741) ([dd68be1](https://github.com/conductorphp/conductor-application-orchestration/commit/dd68be12742188dd8db53b3950be8965b9527805))

<!--- CHANGELOG SPLIT MARKER -->

[4.3.0](https://github.com/conductorphp/conductor-application-orchestration/compare/4.2.1...4.3.0) (2026-09-16)

### Features
* steps run at their default verbosity below -vvv (CTAP-1736) ([99089d9](https://github.com/conductorphp/conductor-application-orchestration/commit/99089d9688142913af79823aefd5507cd8fc71d0))

<!--- CHANGELOG SPLIT MARKER -->

[4.2.1](https://github.com/conductorphp/conductor-application-orchestration/compare/4.2.0...4.2.1) (2026-09-15)

### Bug Fixes
* placeholder parser for replacements (CTAP-1728) ([31f4558](https://github.com/conductorphp/conductor-application-orchestration/commit/31f455864fae2df3583fe0288e44147a00f72d46))

<!--- CHANGELOG SPLIT MARKER -->

[4.2.0](https://github.com/conductorphp/conductor-application-orchestration/compare/4.1.0...4.2.0) (2026-09-15)

### Features
* interpolation across merged config (CTAP-1724) ([66cce7a](https://github.com/conductorphp/conductor-application-orchestration/commit/66cce7a83287ccb4f75559f7c8be31324fef6083))

<!--- CHANGELOG SPLIT MARKER -->

[4.1.0](https://github.com/conductorphp/conductor-application-orchestration/compare/4.0.1...4.1.0) (2026-09-14)

### Features
* conductor/core ^5.0 (CTAP-1712) ([d9274ed](https://github.com/conductorphp/conductor-application-orchestration/commit/d9274edd8d59d3fe7274ad4e564f3d3688e9a750))

<!--- CHANGELOG SPLIT MARKER -->

[4.0.1](https://github.com/conductorphp/conductor-application-orchestration/compare/4.0.0...4.0.1) (2026-09-09)

### Bug Fixes
* null config values in app:config:show instead of fataling (CTAP-1634) ([5fbf1f1](https://github.com/conductorphp/conductor-application-orchestration/commit/5fbf1f1d5577ceaccaa1ac5c057bc3fd3934e35f))

<!--- CHANGELOG SPLIT MARKER -->

[4.0.0](https://github.com/conductorphp/conductor-application-orchestration/compare/3.2.0...4.0.0) (2026-09-08)


<!--- CHANGELOG SPLIT MARKER -->

[3.2.0](https://github.com/conductorphp/conductor-application-orchestration/compare/3.1.1...3.2.0) (2026-09-08)

### Features
* post-import scripts read the schema instead of guessing it (CTAP-1628) ([e832642](https://github.com/conductorphp/conductor-application-orchestration/commit/e832642044c318bfa8ceb905c824ba826bdc833f))

<!--- CHANGELOG SPLIT MARKER -->

[3.1.1](https://github.com/conductorphp/conductor-application-orchestration/compare/3.1.0...3.1.1) (2026-08-11)

### Bug Fixes
* to phpunit 13 (CTAP-1226) ([fd3682b](https://github.com/conductorphp/conductor-application-orchestration/commit/fd3682b25d25d339aadf0c0d2e31351eb57ce6e0))

<!--- CHANGELOG SPLIT MARKER -->

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
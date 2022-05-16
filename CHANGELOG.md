# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2022-05-16

- Created top level application/databases configuration for `alias`, `adapter`, and `importexport_adapter`.

## [Unreleased]
- Added `var_export` Twig extension.
- Added a default list of `source_file_paths` for templates in order to support the
  `var-export.php.twig` template.
- Enabled Twig debug mode and added `DebugExtension` to allow for calling of `dump` in templates.

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

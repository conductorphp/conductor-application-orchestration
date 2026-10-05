Application Orchestration Documentation
=======================================

This module adds [Conductor](https://github.com/conductorphp/conductor-core)
application orchestration functionality, which includes builds, deployments,
and keeping environment assets and databases in sync.

## Installation

```bash
composer require conductor/application-orchestration
```

## Basic Usage

<!-- @todo Add basic usage -->

## Configuration from environment variables

Any string in the application configuration may carry `${VAR}` placeholders, filled at config load
from the process environment and then `application.environment_vars`. Skeleton `template_vars` are
the usual place:

```yaml
# config/app/environments/production/config.yaml
application_orchestration:
  application:
    skeleton:
      files:
        config/autoload/doctrine.local.php:
          location: local
          source: doctrine.local.php.twig
          template_vars:
            data:
              doctrine:
                connection:
                  orm_default:
                    params:
                      url: "mysql://prod:${MYSQL_PASSWORD}@${MYSQL_HOST}/app"
```

Register `ConductorAppOrchestration\Config\EnvVarInterpolationPostProcessor` as a `ConfigAggregator`
post-processor in `config/config.php` to turn it on. An undefined variable fails the load, naming the
variable and the config path; `$${VAR}` writes a literal; plan steps are left to the shell. A
multi-line value such as a PEM key travels as one base64 environment variable and is decoded at the
placeholder, `'${AMAZON_PAY_PRIVATE_KEY|b64decode}'`, which works through the shared
`var-export.php.twig`; a template the project owns can use the same `b64decode` as a Twig filter. The full rules, the `.env.dist` convention and the `config.php` scaffold are in
the [conductor/core documentation](https://github.com/conductorphp/conductor-core/blob/master/docs/index.md#environment-variables-in-configuration).

## Plan step lists

A plan in `deploy.plans`, `build.plans` or `snapshot.plans` holds up to six lists of steps. Every list
takes the same step shape (`command`, `class`, `run_in_code_root`, `conditions`, `depends`, `notice`,
`wait`, `environment_variables`, or a map of steps run in parallel).

| Key | Runs |
| --- | --- |
| `preflight_steps` | First, on every run. |
| `clean_steps` | After the preflight steps, only with `--clean`. |
| `steps` | Required. The plan's main steps. |
| `on_failure_steps` | When a preflight, clean or main step fails, before that failure is rethrown. |
| `rollback_preflight_steps`, `rollback_steps` | Instead of all the above, only with `--rollback`. |

`on_failure_steps` are best-effort: a failing one is logged and the rest still run, and the plan still
fails with the original step's error. The name of the step that failed is in `${FAILED_STEP}` for a
`command:`, `notice:` or `wait:` in them. They do not run for `--rollback`. Use them to undo what an
earlier step left behind, such as maintenance mode:

```yaml
deploy:
  plans:
    development:
      steps:
        enable-maintenance: ConductorAppOrchestration\Deploy\Command\EnableMaintenanceCommand
        migrate: { command: vendor/bin/console migrations:migrate -n, run_in_code_root: true }
        disable-maintenance: ConductorAppOrchestration\Deploy\Command\DisableMaintenanceCommand
      on_failure_steps:
        disable-maintenance: ConductorAppOrchestration\Deploy\Command\DisableMaintenanceCommand
        report:
          notice: Deploy failed at ${FAILED_STEP}; maintenance mode was switched back off.
```

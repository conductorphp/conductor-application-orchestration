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

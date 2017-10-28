Builds & Deployments
========================

The devops tool can create builds, upload them to a remote filesystem, and then deploy them. The build 
process can also be used to prepare a local working directory.

Quick Reference
---------------

### Build

The build command has this signature:

```bash
app:build [options] [--] [<plan>] [<branch>] [<build-id>]
```

The deploy command is not yet built, but will likely have this signature:
```bash
app:deploy [options] [--] <build-id> [<tag>]
```

If you have multiple apps specified in ~/.devops/app-setup.yaml, you will also need to specify which 
application to build/deploy with the `--app` argument.

To run a build in your current working directory, do not specify a build-id or working-directory. You must
run this from the root of your repository.
 
```bash
cd /path/to/your/repo;
devops app:build
```

You can also build a different branch. Your working directory must be clean. 

You can ensure this with the 
--clean flag. **Warning, cleaning your working directory is a destructive operation. You could lose work!**

```bash
cd /path/to/your/repo;
devops app:build development release-1.2.3
``` 

To build a release for deployment, specify a build id. This uses a temporary working directory always and
can be run from anywhere.

```bash
devops app:build production release-1.2.3 1.2.3-rc1
``` 

Configuration
-------------

```
build_plans:

  # Plan name
  development:
    
    # Commands to run when --clean option is specified
    clean_commands:       
    
      # Reference command example
      composer-clean: "@composer::clean"
    
    # Commands to run for build plan
    commands:
    
      # Custom bash command example
      my-script: echo "My script ran"
      
      # Reference command example
      composer-install: "@composer::install-development"
            
  production:
    commands:
      composer-install: "@composer::install-production"
    
      # PHP class command example
      \App\BuildCommand\MinifyJsCommand:
    
        # Options for php class
        paths: 
          - ./path/to/js
        excludes:
          - cache
    
    # Files to exclude from the build artifact
    excludes:
      - ./test/custom/exclude
```

Built-in Build Plans
--------------------

### Composer

#### composer::clean
```bash
composer show --name-only | sed ':a;N;$!ba;s|\n| |g' | xargs composer remove --no-plugins --no-interaction -vvv && git checkout composer.json composer.lock
```

#### composer::install-development
```bash
composer install --no-interaction --no-suggest --optimize-autoloader -vvv
```

#### composer::install-production
```bash
composer install --no-dev --no-interaction --no-suggest --optimize-autoloader -vvv
```

#### \App\BuildCommand\MinifyJsCommand

Options:
- paths: An array of paths to search through for js files to minify
- excludes: An array of paths to exclude from js file search

#### \App\BuildCommand\MinifyCssCommand

Options:
- paths: An array of paths to search through for css files to minify
- excludes: An array of paths to exclude from css file search
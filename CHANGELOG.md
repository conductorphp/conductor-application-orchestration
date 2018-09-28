Conductor: Application Orchestration

# 0.9.10
- Fixed Apache license ID per https://spdx.org/licenses

# 0.9.9
- Added maintenance strategy and code deployment state strategy placeholder classes 
  that will show an exception stating that they are not implemented when no platform
  support package has been included.

# 0.9.8
- Added initial documentation structure 

# 0.9.7
- Added force to snapshot plan

# 0.9.6
- Fixed issue with creating working directory if it did not exist and was 
  more than one level deep

# 0.9.5
- Added consideration to deployment state check that local db name may be different

# 0.9.4
- Added ability to set working directory in cli arguments
- Updated commands to prompt to clear working directory if not empty
  rather than throwing an exception
- Fixed cleaning of working directory after plan completes

# 0.9.3
- Updated conductor/core require to ~0.9.2

# 0.9.2
- Removed -v flag from tar command on app:build
- Added consideration for shallow clone when running app:build

# 0.9.1
- Fixed deployment and snapshot excludes/includes

# 0.9.0
- Renamed to Conductor
- Updated PHP version requirement to 7.1
- Refactored all app commands
- Refactored application configuration to pull from local config rather than app setup repo
- Removed support for multiple apps
- Replaced app:config:list and app:config:get with app:config:show
- Made app:install commands more cohesive
- Added deployment mainenance mode management commands
- Added `provides` argument to steps which works with `depends`. Removed `conditions` from
  being merged into met dependencies.

# 0.1.0
- Initial build copied over from Conductor

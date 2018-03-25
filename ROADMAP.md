Conductor: Application Orchestration Roadmap
=======================

# 1.1.0
- Add destroy plans
- Add more logic for helping with typos. E.g. Similar spelling suggestions.

# 1.0.0
- Add multi-server environment support
- Complete documentation
- Complete PHPUnit tests
- Finalize config structure
- Review all console argument/option documentation
- Consolidate use of $branch vs. $repoReference
- Document that branching strategy is subject to future removal and should not be used. (We will use this internally only)
- Possibly add a flag in PlanRunner to not clean the working directory on error. Might help with debugging.
- Moved *Deployer classes in to Deploy folder
- Add ability to exclude database table data from snapshot with wildcard (or regex?)

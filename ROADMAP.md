Conductor: Application Orchestration Roadmap
=======================

# 1.1.0
- Add destroy plans
- Add more logic for helping with typos. E.g. Similar spelling suggestions.

# 1.0.0
- Add multi-server environment support
- Add "depends" block for steps to ensure code, assets, or databases have been deployed before running the step
- Complete documentation
- Complete PHPUnit tests
- Finalize config structure
- Review all console argument/option documentation
- Consolidate use of $branch vs. $repoReference
- Remove unused FileLayout stuff
- Document that branching strategy is subject to future removal and should not be used. (We will use this internally only)

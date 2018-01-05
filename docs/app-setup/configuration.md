App Setup Configuration
=======================

In order to use the app setup commands, you must first create a file named 
`configuration` with the following format:

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

The `repo_url` lines above point to "App Setup" repositories. These are repositories which
contain all of the information about your app, including where to install the app in all 
environments, files the app requires that are not part of the application repository,
where to push/pull snapshots from, etc.

An App Setup repository's structure looks like this:
```
database_scripts/
  apply-test-settings.sql
environments/
  local/
    database_scripts/
      environment-settings.sql
    files/
      any/files/here/
    roles/
      web/
      admin/
    config.yaml
  uat/
    ...
  production/
    ...
files/
  any/files/here/
roles/
  web/
  admin/
config.yaml
```
 
 This configuration has these levels, from most specific to least. The most specific 
 configuration and files will be used over the least. All configuration is deep merged.
 
 1. environment + role
 2. environment
 3. role
 4. global
 
 The roles here should match roles used in your infrastructure automation (e.g. Puppet)
 if you use something for this.
  
 The configuration will usually be less complicated that that though as most apps do not 
 need to differentiate configuration this much. Also, this tool provides default files
 for some platforms like Magento 1 so that these files don't need to be included with 
 every application.
 
 Most apps will look more like this:
 ```
 database_scripts/
   apply-test-settings.sql
 environments/
   local/
     database_scripts/
       environment-settings.sql
     config.yaml
   uat/
     database_scripts/
       environment-settings.sql
     config.yaml
   production/
     config.yaml
 config.yaml
```

For a complete example, see [App Setup Repository Example](../examples/app-setup-repo/README.md).

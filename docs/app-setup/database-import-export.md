Database Import/Export
======================

The devops tool can create database exports in different formats, upload them to a remote filesystem, and 
import them.

Quick Reference
---------------

The `database:export` command has this signature:

```bash
database:export [options] [--] <database> [<filename>]
```

If you do not specify a filename, the database name will be used to generate the filename.


The `database:import` command has this signature:
```bash
database:import [options] [--] <database> <filename>
```

The file extension depends on the format used for import/export. You must use the same format to import 
that was used for export.

Formats
-------

### Mysqldump

To create a database export using mysqldump, run this:

```bash
devops database:export mydatabase myfilename.sql.gz --format sql
```

To import it, run this:
```bash
devops database:import mydatabase myfilename.sql.gz --format sql
```

### Mydumper

To create a database export using mydumper, you must first install mydumper. Then run this command:

```bash
devops database:export mydatabase myfilename.tgz --format mydumper
```

And to import:

```bash
devops database:import mydatabase myfilename.tgz --format mydumper
```

### Tab Delimited

To create a tab delimited database export, run this. 

```bash
devops database:export mydatabase myfilename.tgz --format tab
```

And to import:
```bash
devops database:import mydatabase myfilename.tgz --format tab
```

Note: You can leave off the file extension from the filename argument. The command will append the file 
extension if not set.
 
Installing Mydumper
-------------------
See [Mydumper](https://github.com/maxbube/mydumper) for complete installation instructions.
 
If you are using Puppet, you can install this via the robofirm/mydumper module.
 
On RedHat, installation looks something like this:

```bash
yum install gcc glib2-devel mysql-devel zlib-devel pcre-devel openssl-devel cmake
cd /usr/local/src
git clone https://github.com/maxbube/mydumper.git
cd mydumper
cmake .
make
make install
```
 
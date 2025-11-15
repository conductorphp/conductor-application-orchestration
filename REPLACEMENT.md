# Database Replacements for Snapshot Scrubbing

## Overview

This feature automatically replaces production strings (URLs, email domains, etc.) with environment-specific values when deploying database snapshots. This eliminates the need to manually fix references after pulling production data.

## How It Works

When you deploy a database snapshot using `app:deploy --databases`, the system runs three post-import scripts in order:

1. **apply-test-settings.sql** - Removes production-specific settings
2. **DatabaseReplacementScript** - Performs named replacements based on configuration (NEW)
3. **environment-settings.sql** - Applies environment-specific configuration

The DatabaseReplacementScript:
- Implements `PostImportScriptInterface` as a proper class
- Only runs in non-production environments (local, qa, uat)
- Uses named replacements with per-replacement targets
- Supports both simple string replacement and regex with capture groups
- Validates tables and columns exist before generating SQL
- **Case-sensitive by default** for simple replacements
- **Works with JSON fields** - Automatically handles both plain and JSON-escaped values
- **JSON escape handling** - Uses nested REPLACE() to update both `https://domain.com` and `https:\/\/domain.com`

## Configuration

### Structure

Replacements are configured **per-database** with named replacements:

**Global config** defines the pattern (`from`) and targets:
**Environment config** only overrides the replacement value (`to`)

This allows the same replacement logic across environments with environment-specific values.

### Configuration Format

The replacement configuration uses a structured format with identifiers, columns, and replacements:

```yaml
replacements:
  table_name:                    # Database table
    identifier:                  # User-defined label for this set of operations
      columns: [col1, col2]      # Array of columns to target
      replacements:              # Replacement definitions
        replacement_name:
          from: '...'
          to: '...'
          regex: false
```

### 1. Global Configuration (`global.yaml`)

```yaml
application_orchestration:
  application:
    deploy:
      databases:
        crispipwa_magento:  # Per-database configuration
          replacements:
            core_config_data:
              urls:  # Identifier for URL replacements
                columns:
                  - value
                replacements:
                  base_url:
                    from: 'https://www.crispiusa.com'
                    to: ''  # Will be overridden in environment configs
                  magento_url:
                    from: 'https://magento.crispiusa.com'
                    to: ''
                  email_domain:
                    from: '@crispiusa.com'
                    to: ''

            cms_page:
              content:  # Identifier for content replacements
                columns:
                  - content
                replacements:
                  base_url:
                    from: 'https://www.crispiusa.com'
                    to: ''

            cms_block:
              content:  # Identifier for content replacements
                columns:
                  - content
                replacements:
                  base_url:
                    from: 'https://www.crispiusa.com'
                    to: ''
```

### 2. Environment Configuration (`environments/local/config.yaml`)

Only override the `to` value for each replacement (maintains same structure):

```yaml
application_orchestration:
  application:
    deploy:
      databases:
        crispipwa_magento:
          replacements:
            core_config_data:
              urls:
                replacements:
                  base_url:
                    to: 'https://crispipwa.local-rmgmedia.com'
                  magento_url:
                    to: 'https://magento-crispipwa.local-rmgmedia.com'
                  email_domain:
                    to: '@local-rmgmedia.com'

            cms_page:
              content:
                replacements:
                  base_url:
                    to: 'https://crispipwa.local-rmgmedia.com'

            cms_block:
              content:
                replacements:
                  base_url:
                    to: 'https://crispipwa.local-rmgmedia.com'
```

### Example: Multiple Columns

When the same replacements need to apply to multiple columns, specify them in the `columns` array:

```yaml
replacements:
  content_builder_entity_attribute:
    attributes:  # Identifier for attribute-related replacements
      columns:
        - attributes_global
        - attributes_website
        - attributes_view
      replacements:
        base_url:
          from: 'https\:\/\/(www\.)?crispiusa?\.com'
          to: 'https://${FRONTEND_DOMAIN}'
          regex: true
        wholesale_base_url:
          from: 'https://wholesale.crispius.com'
          to: 'https://${WHOLESALE_DOMAIN}'
        email_domain:
          from: '@crispius.com'
          to: '@${EMAIL_DOMAIN}'
```

This configuration will apply all three replacements (`base_url`, `wholesale_base_url`, `email_domain`) to all three columns (`attributes_global`, `attributes_website`, `attributes_view`).

**Benefits:**
- No duplication - define each replacement once
- Clear separation: `columns` defines targets, `replacements` defines operations
- Easy to add/remove columns or replacements
- Environment variable interpolation: `${VAR_NAME}` is replaced at runtime

## Configuration Fields

### At the identifier level:
- **`columns`** (required): Array of column names to target
- **`replacements`** (required): Object containing replacement definitions

### Within each replacement:
- **`from`** (required): The pattern to search for (from production)
- **`to`** (required): The replacement value for this environment (can be empty string in global, override in environment configs)
- **`regex`** (optional, default `false`): Use regex mode with `REGEXP_REPLACE()`

## Adding New Replacements

### 1. Add to Global Config

Edit `config/app/global.yaml`:

```yaml
replacements:
  custom_table:
    api_config:  # Identifier for this set of replacements
      columns:
        - api_url
        - backup_api_url
      replacements:
        api_endpoint:
          from: 'https://api.example.com'
          to: ''  # Override in environment config
```

### 2. Override in Environment

Edit `config/app/environments/local/config.yaml`:

```yaml
replacements:
  custom_table:
    api_config:
      replacements:
        api_endpoint:
          to: 'https://api.local-example.com'
```

### 3. Deploy

Changes take effect on next database deployment.

## Regex Support

For complex patterns, use `regex: true` to enable `REGEXP_REPLACE()` with capture groups:

**Global:**
```yaml
replacements:
  core_config_data:
    urls:
      columns:
        - value
      replacements:
        base_url_with_protocol:
          from: 'https?://(www\.)?crispiusa\.com'
          to: 'https://\1crispipwa.local-rmgmedia.com'  # \1 is capture group
          regex: true
```

**Environment (optional override):**
```yaml
replacements:
  core_config_data:
    urls:
      replacements:
        base_url_with_protocol:
          to: 'https://\1different.local-rmgmedia.com'
```

**Requirements:**
- MySQL 8.0+ (for `REGEXP_REPLACE()`)
- Backslashes must be escaped in YAML strings (use `'https://\\1domain.com'` or `"https://\\\\1domain.com"`)

## How Replacements Work

For each replacement configuration, the script will:
1. Read the table name and identifier
2. Read the `columns` array to determine which columns to target
3. Read each replacement definition in `replacements`
4. Verify the table exists in the database
5. For each column in the `columns` array:
   - Verify the column exists in the table
   - Generate appropriate UPDATE statement (with automatic JSON escape handling)
6. Execute the generated SQL

Missing tables or columns are skipped with a debug log message.

## Usage

### Deploy with Replacements

```bash
# Deploy a database snapshot (replacements run automatically)
app:deploy --databases --snapshot <snapshot-name>

# With verbose output to see replacement details
app:deploy --databases --snapshot <snapshot-name> -vvv
```

### Skip Replacements

To skip replacements:
1. Remove `DatabaseReplacementScript` from `post_import_scripts` in `deployment-plans.yaml`, or
2. Don't define `to` values in environment config (replacements with empty `to` are skipped)

## JSON Escape Handling

The replacement script **automatically** handles JSON-escaped values without requiring any special configuration.

### Problem

When values are stored in JSON fields, forward slashes are escaped:
- Plain value: `https://www.crispiusa.com`
- JSON-escaped: `https:\/\/www.crispiusa.com`

A simple `REPLACE()` operation would only replace one or the other, leaving some values unchanged.

### Solution

The script uses **nested REPLACE()** operations to handle both formats in a single UPDATE statement:

```sql
UPDATE `table`
SET `column` = REPLACE(
    REPLACE(`column`, 'https:\/\/www.crispiusa.com', 'https:\/\/dev.crispiusa.com'),
    'https://www.crispiusa.com',
    'https://dev.crispiusa.com'
)
WHERE `column` LIKE '%https://www.crispiusa.com%'
   OR `column` LIKE '%https:\\\\/\\\\/www.crispiusa.com%';
```

**Key points:**
1. **JSON-escaped replacement first** - Avoids double-escaping issues
2. **Plain replacement second** - Catches non-JSON fields
3. **WHERE clause with OR** - Efficiently targets only rows containing either format
4. **Four backslashes in LIKE** - Required for matching JSON-escaped slashes:
   - Two backslashes for SQL string literal escaping
   - Two backslashes for LIKE pattern escaping

### What Gets Updated

Given a replacement from `https://www.crispiusa.com` to `https://dev.crispiusa.com`:

| Original Value in DB | After Replacement |
|---------------------|-------------------|
| `https://www.crispiusa.com` | `https://dev.crispiusa.com` |
| `https:\/\/www.crispiusa.com` | `https:\/\/dev.crispiusa.com` |
| `Visit https://www.crispiusa.com today!` | `Visit https://dev.crispiusa.com today!` |
| `{"url":"https:\/\/www.crispiusa.com"}` | `{"url":"https:\/\/dev.crispiusa.com"}` |

### Automatic Behavior

- **Always enabled** for both simple and regex replacements
- **No configuration needed** - Just define your `from` and `to` values (or patterns)
- **Works with forward slashes** - The most common JSON-escaped character
- **Regex mode** automatically adjusts patterns to match JSON-escaped values

### How It Works for Regex

When `regex: true`, the script automatically generates patterns to match both plain and JSON-escaped:

**Your config:**
```yaml
from: 'https\:\/\/(www\.)?crispiusa?\.com'
to: 'https://${FRONTEND_DOMAIN}'
regex: true
```

**What the script does:**
1. **Plain pattern**: Uses your pattern as-is: `https\:\/\/(www\.)?crispiusa?\.com`
   - Matches: `https://www.crispiusa.com`, `https://crispiusa.com`
2. **JSON pattern**: Transforms `\/` → `\\/` in pattern: `https\:\\\/\/(www\.)?crispiusa?\.com`
   - Matches: `https:\/\/www.crispiusa.com`, `https:\/\/crispiusa.com`
3. **Replacement**: Also escapes forward slashes in `to` value for JSON context
   - Plain: `https://${FRONTEND_DOMAIN}` → `https://dev.example.com`
   - JSON: `https:\/\/${FRONTEND_DOMAIN}` → `https:\/\/dev.example.com`

**Key insight**: When you write `\/` in your regex pattern (escaped forward slash), the script knows this is a literal `/` character and automatically generates a version that matches the JSON-escaped `\/` in the database.

## Technical Details

### DatabaseReplacementScript Class

Located at: `vendor/conductor/application-orchestration/src/Deploy/DatabaseReplacementScript.php`

Implements `PostImportScriptInterface`:

```php
public function execute(
    DatabaseAdapterInterface $databaseAdapter,
    string $databaseName,
    array $config,
    LoggerInterface $logger
): string;
```

**Process:**
1. Checks environment (skips if production)
2. Loads replacements config for the specific database
3. Merges environment overrides with global config
4. Validates all replacements have required fields
5. For each replacement, processes all targets
6. Validates each table and column exists
7. Generates SQL based on `regex` flag
8. Returns SQL string for execution

### Generated SQL

**Non-Regex (simple string replacement with automatic JSON escape handling):**
```sql
UPDATE `core_config_data`
SET `value` = REPLACE(
    REPLACE(`value`, 'https:\/\/www.crispiusa.com', 'https:\/\/crispipwa.local-rmgmedia.com'),
    'https://www.crispiusa.com',
    'https://crispipwa.local-rmgmedia.com'
)
WHERE `value` LIKE '%https://www.crispiusa.com%'
   OR `value` LIKE '%https:\\\\/\\\\/www.crispiusa.com%';
```

**Regex (pattern replacement with capture groups and automatic JSON escape handling):**
```sql
UPDATE `core_config_data`
SET `value` = REGEXP_REPLACE(
    REGEXP_REPLACE(`value`, 'https\\:\\\\\/\/(www\.)?crispiusa?\.com', 'https:\/\/\\1dev.example.com'),
    'https\:\/\/(www\.)?crispiusa?\.com',
    'https://\\1dev.example.com'
)
WHERE `value` REGEXP 'https\:\/\/(www\.)?crispiusa?\.com'
   OR `value` REGEXP 'https\\:\\\\\/\/(www\.)?crispiusa?\.com';
```

Note: The first REGEXP_REPLACE handles JSON-escaped URLs (`https:\/\/`), the second handles plain URLs (`https://`).

### Post-Import Script Types

The deployment system supports three types of post-import scripts:

1. **Class name** (recommended): `ConductorAppOrchestration\Deploy\DatabaseReplacementScript`
   - Must implement `PostImportScriptInterface`
   - Instantiated and executed

2. **PHP file returning object**: `url-replacement.sql.php`
   - Must return an object implementing `PostImportScriptInterface`
   - Executed via `include`

3. **SQL file**: `environment-settings.sql`
   - Plain SQL executed directly

## Creating Custom Post-Import Scripts

You can create your own post-import script classes:

```php
<?php

namespace YourNamespace;

use ConductorAppOrchestration\Deploy\PostImportScriptInterface;
use ConductorCore\Database\DatabaseAdapterInterface;
use Psr\Log\LoggerInterface;

class CustomScript implements PostImportScriptInterface
{
    public function execute(
        DatabaseAdapterInterface $databaseAdapter,
        string $databaseName,
        array $config,
        LoggerInterface $logger
    ): string {
        $logger->info("Running custom script for database: {$databaseName}");

        // Access per-database config
        $dbConfig = $config['application_orchestration']['application']['deploy']['databases'][$databaseName] ?? [];

        // Your logic here

        // Return SQL to execute
        return "UPDATE your_table SET your_column = 'value';";
    }
}
```

Add to `deployment-plans.yaml`:

```yaml
post_import_scripts:
  - apply-test-settings.sql
  - YourNamespace\CustomScript
  - environment-settings.sql
```

## Troubleshooting

### No Replacements Running

1. Check that `DatabaseReplacementScript` is in `post_import_scripts` in `deployment-plans.yaml`
2. Verify `to` values are defined in environment config (empty `to` values are skipped)
3. Check you're deploying with `--databases` flag
4. Ensure you're not in production environment
5. Run with `-vvv` to see detailed logs

### Replacement Not Working

1. Check that replacement name in environment matches name in global exactly
2. Verify `targets` are defined in global config
3. Confirm table and column names are correct (check debug logs for validation failures)
4. For regex, ensure pattern is valid MySQL regex syntax

### Error: "missing 'from' field"

The replacement in global config is missing the `from` field. Add it:

```yaml
my_replacement:
  from: 'pattern to find'
  to: ''
  targets: [...]
```

### Error: "class does not exist"

The class name in `post_import_scripts` cannot be found. Ensure:
1. Class file exists in conductor module
2. Namespace matches file location
3. Class is autoloadable

## Future Enhancements

Potential improvements:
- Row count reporting (how many rows were actually updated)
- Support for custom replacement functions
- Ability to exclude specific targets per environment
- Dry-run mode to preview changes without applying
- Conditional replacements based on column value patterns

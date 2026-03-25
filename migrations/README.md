# Migrations

Store SQL migration files in this directory.

## Naming convention

Use timestamped, descriptive file names in UTC:

`YYYYMMDDHHMMSS_<short-description>.sql`

Examples:

- `20260325090000_create_assets_table.sql`
- `20260325091500_add_asset_status_index.sql`

## Guidelines

- One migration purpose per file.
- Prefer additive and reversible changes.
- Keep SQL idempotent when possible.
- Use prepared statements in application code; migrations should only define schema/data changes.

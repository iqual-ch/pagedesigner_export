# Pagedesigner MCP

Read-only MCP tools exposing the `pagedesigner_export` migration Exporter to
the iqual ai-platform content-migrator agent — the **source-side sibling of
`icms_mcp`**. Full parity with the `drush pd:migration-export` file package
(schema 0.3.0): the agent ingests either transport identically; the drush
package stays the fallback for local clones and offline analysis.

## Tools

| Tool | Package equivalent | Notes |
|---|---|---|
| `get_migration_manifest` | `manifest.json` | + step-3 content summary; no per-page hashes (fetch trees) |
| `get_page_tree` | `pages/*.json` | one tree, all translations, media URLs; returns `content_hash` for incremental sync |
| `get_taxonomies` | `taxonomies.json` | import before nodes |
| `get_menus` | `menus.json` | import after nodes |
| `get_entities` | `entities/*.json` | non-Pagedesigner entities; optional `entity_keys` filter |

## Security model

The export contains **everything, including unpublished/intranet content**.
Therefore:

- Enable the module **only during a site's migration window**; uninstall
  after. Install creates a `pagedesigner_mcp` role (only `Use MCP server` +
  `Use Pagedesigner MCP tools`) and an active `pagedesigner_mcp` user with a
  generated password **printed exactly once**; uninstall deletes both — no
  standing credential on the fleet.
- Rotate any time with `drush upwd pagedesigner_mcp '<new password>'`.
- The role is a config entity: run `drush cex` after install, or the next
  config import deletes it.

## Setup

```bash
drush en pagedesigner_mcp -y   # note the printed password
```

Then `/admin/config/mcp` — enable token auth and the `pagedesigner-mcp`
plugin (hyphen, not underscore).

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
  after. Install provisions the full OAuth 2.1 chain and uninstall removes
  it — no standing credential on the fleet.

## Authentication — OAuth 2.1 client_credentials

Install (and `drush updb`, update 10101) provisions everything:

1. simple_oauth **signing keys** outside the webroot (`../keys/`, never in
   git) — skipped when the site already has keys.
2. A `pagedesigner_mcp` **scope** with ROLE granularity: tokens carry exactly
   the `pagedesigner_mcp` role's permissions.
3. A **confidential consumer** (`client_id: pagedesigner_mcp`,
   client_credentials grant, 1h tokens) bound to a **passwordless** service
   user. The `client_id` + `client_secret` print **exactly once** — paste
   them into the cockpit connection form. Rotate with
   `drush pagedesigner-mcp:rotate-secret`.
4. A route subscriber allows the `oauth2` provider on `/mcp` (mcp_server
   declares `_auth: ['cookie']`, which excludes even global providers).

Consumer, scope, and role are config: run `drush cex` after install, or the
next config import deletes them.

## Setup

```bash
composer require iqual/pagedesigner_export   # pulls mcp_server + simple_oauth + consumers
drush en pagedesigner_mcp -y                 # note the printed client credentials
drush cex -y
```

MCP endpoint: `POST /mcp` with `Authorization: Bearer <access_token>` from
`POST /oauth/token` (grant_type=client_credentials, scope=pagedesigner_mcp).

The legacy drupal/mcp plugin (`/mcp/post`, basic auth) keeps working on
sites that still have `drupal/mcp` enabled — transition only.

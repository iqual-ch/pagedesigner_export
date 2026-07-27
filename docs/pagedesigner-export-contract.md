# Pagedesigner Export Module Contract

## Purpose

The `pagedesigner_export` module should stay focused on the legacy Drupal source project. It should export enough structured data for the migration runner without depending on ICMS, blökkli, Paragraphs, AI tooling, or the target importer.

## Package Boundary

`pagedesigner_export` owns reading pagedesigner roots, exporting element trees, discovering source nodes/pages, exporting source metadata, and producing stable JSON artifacts.

It must not own ICMS Paragraph mapping, blökkli target decisions, AI-assisted mapping, Drupal Migrate API target import, or target content creation.

## Required Export Artifacts

```text
manifest.json
pages/
  node-123-root-456.json
media.json         optional, later
links.json         optional, later
references.json    optional, later
```

## Manifest Schema

Current schema version: `0.2.0` (additive over `0.1.0`).

The manifest must contain `schema_version`, `source`, and `pages`. Each page should include source entity metadata, language metadata, title/path maps, `pagedesigner_root_id`, `pagedesigner_field`, and `export_file`.

Schema `0.2.0` adds per page (all additive, omitted when empty):

- `created` / `changed` — per-langcode unix timestamps
- `authors` — per-langcode `{uid, name}`
- `taxonomies` — `[{field, terms: [{tid, uuid, name, labels, vocabulary}]}]`
- `menu_links` — `[{menu_name, title, parent, weight, enabled}]`
- `redirects` — `[{source, langcode, status_code}]` (requires the `redirect` module)
- `content_hash` — sha1 of the page tree export, so re-exports can skip unchanged pages

## Existing Tree Export Compatibility

Each `export_file` should keep the existing `pagedesigner_export` tree shape: `root_id`, `default_langcode`, `exported_at`, and `elements`.

## Suggested New Drush Commands in `pagedesigner_export`

- `pd:migration-manifest`
- `pd:migration-export`

## Minimum MVP Changes Needed in `pagedesigner_export`

1. Discover source nodes containing pagedesigner root references.
2. Export a manifest with page metadata and export file paths.
3. Export one pagedesigner tree JSON per page/root.
4. Include multilingual title/path/status metadata in the manifest.
5. Keep the tree export target-independent.

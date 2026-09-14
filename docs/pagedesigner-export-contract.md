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

## Schema `0.9.0` — webforms

Additive. The package gains `webforms.json` (`content.webforms_file`, with
`content.webform_count` / `content.webform_submission_count`); the MCP module exposes
the same document as `get_webforms` (`include_submissions`, default true).

```jsonc
{
  "webforms": [
    {
      "id": "kontakt", "uuid": "…", "label": "Kontaktformular", "status": true, "langcode": "de",
      "config": { "id": "kontakt", "title": "Kontaktformular", "elements": "name:\n  '#type': textfield\n…",
                  "settings": { … }, "handlers": { "email": { … } }, "access": { … }, … },
      "configTranslations": { "fr": { "title": "Formulaire de contact", "elements": "…" } },
      "submissionCount": 312,
      "submissions": [
        { "sid": 17, "uuid": "…", "created": 1700000000, "completed": 1700000010, "changed": 1700000010,
          "in_draft": false, "langcode": "de", "remote_addr": "203.0.113.7",
          "uid": 5, "mail": "j.doe@example.org", "entity_type": "node", "entity_id": "123",
          "sticky": false, "locked": false, "notes": "", "data": { "name": "Jane", "message": "…" } }
      ]
    }
  ]
}
```

- `config` is the `webform.webform.<id>` config object **verbatim** (minus `_core`): the target
  recreates the form from it as-is, handlers and recipients included. `configTranslations`
  holds the `language.<lc>.webform.webform.<id>` overrides per non-default language.
- A submission's `uid` is a source uid; `mail` is that account's e-mail, which is what the
  target re-links the submitter by (anonymous when empty or unknown). `entity_type` /
  `entity_id` name the source entity the form was submitted on — for the record only.
- `example_*` and `template_*` forms are skipped. A site without the webform module answers
  `{"webforms": []}`, never an error.
- Pagedesigner places a form with a `webform` element: `type: "webform"`,
  `fields.field_webform[0].target_id = "<id>"`.

## Schema `0.8.0` — the design as data

Additive. The package gains `theme.json` (`content.theme_file`) and the manifest a
`theme` summary (`{name, baseTheme, palette}`); the MCP module exposes the same
document as `get_theme_settings`.

```jsonc
{
  "theme":    { "name": "iq_custom", "baseTheme": "iq_barrio", "chain": ["iq_custom", "iq_barrio", "bootstrap_barrio"],
                "settingsConfig": "iq_barrio.settings" },
  "settings": { "color_primary": "#e12722", "h1_font_family": "Lato", "button_border_radius": "5", … },
  "palette":  { "primary": "#e12722", "secondary": "#db5a42", "tertiary": "#15151f", "quaternary": "#a57f60",
                "grey1": "#475e61", …, "grey5": "#eeeeee", "black": "#000000", "white": "#ffffff" },
  "patterns": {
    "rowoneone": {
      "type": "row", "styles": true,
      "classes":        { "fullwidth": { "label": "Full width", "description": "…", "responsive": true }, … },
      "stylingOptions": { "background_color": { "label": "Background color",
                                                "options": { "background-color-primary": "Primary color", … } }, … }
    }, …
  }
}
```

- `settings` is `iq_barrio.settings` verbatim (scalars only). Every `*_color*` key
  other than the palette holds a palette NAME; `palette` is how a consumer turns
  `h1_color: tertiary` into `#15151f`.
- `patterns` mirrors the `classes` / `styling_options` blocks of each Pagedesigner
  `*.ui_patterns.yml`. `responsive: true` means the editor stores the class as
  `<key>-<large|medium|small>`. This is what tells a consumer that `inverted` is a
  variant, `fullwidth-small` a breakpoint flag and `background-color-grey5` a colour pick.
- A site without iq_barrio or UI Patterns answers with empty layers, never an error.

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

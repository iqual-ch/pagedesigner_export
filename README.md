# Pagedesigner Export/Import Module

Exports and imports Pagedesigner element trees with full translation support. Supports two modes:

1. **Preserve-IDs mode** — Surgical restore of element trees with exact ID preservation
2. **Clone mode** — Clone element trees with new auto-assigned IDs to different nodes or sites

## Technical Handoff

For implementation details, bug history, and AI/developer troubleshooting notes, see `AI_HANDOFF.md` in this module directory.

## Features

- ✅ Full translation support (all languages exported/imported)
- ✅ Recursive tree walking (children + style element references)
- ✅ All configurable element types (content, image, gallery, webform, block, etc.)
- ✅ Preserves custom fields (field_content, field_media, field_block, field_webform, etc.)
- ✅ ID mapping for clone operations (all references fixed automatically)
- ✅ Dry-run validation mode
- ✅ CLI-first with Drush commands (GUI planned)
- ✅ JSON format for portability

## Use Cases

### 1. Restore deleted content to production (Preserve-IDs mode)

When elements are accidentally deleted from a live site but exist in a good local snapshot.

Note: The argument for pd:export is the root Pagedesigner element ID (container), not the node ID. In this example, node 516014 points to container/root element 509413.

```bash
# On local environment (good snapshot)
# 509413 = root Pagedesigner container element ID linked from node 516014
# (for example: node.field_pd_manual_content.target_id)
drush pd:export 509413 --output=/tmp/pd-root-509413.json --sanitize-local-urls

# Transfer file to production, then:
drush pd:import /path/to/pd-root-509413.json --dry-run
drush pd:import /path/to/pd-root-509413.json
drush cr
```

**Important**: Preserve-IDs mode requires the exact same element IDs to be free on the target system. It fails if any ID collision exists.

### 2. Clone a tree to a different field or node (Clone mode)

When moving or duplicating content within the same site or to another site.

```bash
# Export from production
drush pd:export 509413 --output=/tmp/tree-export.json

# Import to a different node/field locally
drush pd:import /tmp/tree-export.json \
  --mode=clone \
  --target-node=516014 \
  --target-field=field_pd_manual_content \
  --dry-run

drush pd:import /tmp/tree-export.json \
  --mode=clone \
  --target-node=516014 \
  --target-field=field_pd_manual_content
```

**Result**: New element tree is created with auto-assigned IDs, all references fixed, and target node field updated.

### 3. Migrate content between sites

Export from site A, import to site B:

```bash
# On site A (production)
drush pd:export 12345 --output=/tmp/page-tree.json

# Copy file to site B and import
drush pd:import /tmp/page-tree.json \
  --mode=clone \
  --target-node=456 \
  --target-field=field_pagedesigner_content
```

## Drush Commands

### `drush pd:export <element-id> [options]`

Export a Pagedesigner element tree and all translations to JSON.

**Arguments:**

- `element-id` — The root element ID to export (usually a container element)

**Options:**

- `--field=FIELDNAME` — Field name for reference (informational only, optional)
- `--output=FILE` — Save JSON to file (if omitted, output to stdout)
- `--langcode=LANG` — Default language code (default: `de`)
- `--sanitize-local-urls` — Convert absolute local URLs to relative paths (default: `1`/YES). Set to `0` to disable if you need absolute URLs

**Examples:**

```bash
# Export to stdout (useful for testing)
drush pd:export 242821

# Export to file
drush pd:export 242821 --output=/tmp/my-tree.json

# With field hint
drush pd:export 242821 --field=field_pd_manual_content --output=/tmp/tree.json

# Different default language
drush pd:export 242821 --langcode=en --output=/tmp/tree.json
```

### `drush pd:import <json-file> [options]`

Import a Pagedesigner element tree from JSON.

**Arguments:**

- `json-file` — Path to the JSON export file

**Options:**

- `--mode=MODE` — Import mode: `preserve` (default) or `clone`
- `--target-node=NID` — Target node ID (required for clone mode)
- `--target-field=FIELD` — Target pagedesigner field (required for clone mode)
- `--skip-existing` — Skip elements that already exist (useful for merges)
- `--dry-run` — Validate without writing
- `--sanitize-local-urls` — Convert absolute local URLs to relative paths (default: `1`/YES). Set to `0` to disable if you need absolute URLs

**Examples:**

```bash
# Surgical restore (preserve exact IDs)
drush pd:import /tmp/tree.json --dry-run
drush pd:import /tmp/tree.json

# Clone to different field (new IDs)
drush pd:import /tmp/tree.json \
  --mode=clone \
  --target-node=516014 \
  --target-field=field_pd_manual_content

# Clone with safety checks
drush pd:import /tmp/tree.json \
  --mode=clone \
  --target-node=516014 \
  --target-field=field_pd_manual_content \
  --dry-run
```

## Modes Explained

### Preserve-IDs Mode (Default)

```bash
drush pd:import /tmp/tree.json
```

**How it works:**

- Creates/updates elements with **exact same IDs** from export
- All references (container, parent, children, styles) map directly
- Fails if any ID already exists on target system

**When to use:**

- Restoring from a known good snapshot to the exact same environment
- Safety: Only works if target IDs are available

**Risk:** Will fail gracefully if IDs collide, so safe to try.

### Clone Mode

```bash
drush pd:import /tmp/tree.json --mode=clone --target-node=516014 --target-field=field_pd_manual_content
```

**How it works:**

1. Creates new elements with auto-assigned IDs
2. Builds ID map: `{old_id → new_id}`
3. Fixes all references (container, parent, children, styles) using map
4. Updates target node's field to point to new root container

**When to use:**

- Duplicating content
- Migrating between sites
- Cloning to different node/field
- When you don't know if IDs exist on target

**Safer:** Always produces fresh IDs, avoids collisions.

## JSON Format

Export format for reference:

```json
{
  "root_id": 509413,
  "default_langcode": "de",
  "exported_at": 1713174000,
  "elements": {
    "509413": {
      "de": {
        "type": "container",
        "name": "Root Container",
        "status": true,
        "langcode": "de",
        "children": [
          { "target_id": 509414 },
          { "target_id": 509415 }
        ],
        "fields": {
          "field_content": []
        }
      },
      "en": {
        "type": "container",
        "name": "Root Container (EN)",
        "status": true,
        "langcode": "en",
        "children": [
          { "target_id": 509414 },
          { "target_id": 509415 }
        ]
      }
    },
    "509414": {
      "de": {
        "type": "content",
        "name": "Text Element",
        "status": true,
        "langcode": "de",
        "container": 509413,
        "parent": 509413,
        "fields": {
          "field_content": [
            { "value": "Hello World", "format": "basic_html" }
          ]
        }
      }
    }
  }
}
```

## Workflow: Restoring Lost Content on Production

Your scenario: Node 516014 was broken on live, but you have a good local snapshot.

### Step 1: Export from local

```bash
# On local (with good snapshot activated)
drush pd:export 509413 --output=/tmp/backup-tree.json
# 509413 is field_pd_manual_content root container from good snapshot
```

### Step 2: Transfer file

```bash
# Local to production
scp /tmp/backup-tree.json user@production:/tmp/
```

### Step 3: Validate on production (dry-run)

```bash
# On production (in maintenance window)
drush pd:import /tmp/backup-tree.json --dry-run
# Should say "Validation successful"
```

### Step 4: Import on production

```bash
# The surgical restore
drush pd:import /tmp/backup-tree.json
# Wait for completion...
drush cr
```

### Step 5: Verify

```bash
# Check the page loads
curl https://yoursite.com/de/node/516014
drush ws --severity=error --count=10  # Check for errors
```

## Future: GUI Interface

The module is designed for GUI extension. The Exporter/Importer services are independent of Drush and can be integrated into a web form:

- Export modal: Select node > Select field > Download JSON
- Import modal: Upload JSON > Choose mode > Dry-run > Confirm

## Troubleshooting

### "Invalid JSON" error

The JSON file is corrupt or not readable:

```bash
# Test file validity
cat /tmp/tree.json | python3 -m json.tool > /dev/null && echo "Valid"
```

### "Element not found" during import

An element referenced as a child/style doesn't exist in the export:

```bash
# Check which elements are in the export
cat /tmp/tree.json | python3 -c "import sys, json; data=json.load(sys.stdin); print('Elements:', list(data['elements'].keys()))"
```

### "Target node does not have field" error

The target node doesn't have the specified pagedesigner field:

```bash
# Check available fields
drush devel:entity:load node 516014 --limit-fields | grep pagedesigner
```

### Clone mode ID mappings look wrong

Enable debug logging to see ID mappings during import:

```bash
# Check Drupal logs
drush ws --severity=notice --count=30 | grep pagedesigner_export
```

## Development Notes

### Directory Structure

```ini
pagedesigner_export/
├── src/
│   ├── Commands/
│   │   └── PagedesignerExportCommands.php    # Drush commands
│   └── Service/
│       ├── Exporter.php                      # Tree walk & serialization
│       ├── Importer.php                      # Tree creation & ID fixing
│       └── IdMapper.php                      # ID mapping helper
├── pagedesigner_export.info.yml              # Module metadata
├── pagedesigner_export.services.yml          # Service definitions
├── drush.services.yml                        # Drush command registration
└── README.md                                 # This file
```

### API for GUI Integration

```php
// In a custom form controller:
$exporter = \Drupal::service('pagedesigner_export.exporter');
$data = $exporter->export($element_id, $langcode);
$json = json_encode($data);

$importer = \Drupal::service('pagedesigner_export.importer');
$result = $importer->import($data, [
  'mode' => 'clone',
  'target_nid' => 516014,
  'target_field' => 'field_pd_manual_content',
  'dry_run' => TRUE,
]);
```

### Testing

Verify both modes work correctly:

```bash
# Test export
drush pd:export 242821 --output=/tmp/test-export.json

# Test preserve-ids (requires fresh/dev DB)
drush pd:import /tmp/test-export.json --dry-run

# Test clone
drush pd:import /tmp/test-export.json \
  --mode=clone \
  --target-node=516014 \
  --target-field=field_pagedesigner_content \
  --dry-run
```

## Security Considerations

- Module requires `administer site configuration` permission (Drush usage)
- No user-facing UI (yet) — requires trusted admin
- Import validates all field references before writing
- Dry-run mode prevents accidental data loss

## See Also

- [Pagedesigner Module](https://www.drupal.org/project/pagedesigner) — Core element management
- [Pagedesigner Debug Module](https://www.drupal.org/project/pagedesigner_debug) — Structure repair tools (do not use on production without understanding the impact)

## Credits

Built to restore lost Pagedesigner content after the `pagedesigner_debug` module's orphan detection incorrectly flagged elements as dangling when multiple pagedesigner fields existed on the same node.

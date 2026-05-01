# Pagedesigner Export Module: AI Handoff Notes

This file is a technical handoff for future AI/dev work on this module.
It complements README.md (which is user-facing) with implementation history,
known pitfalls, and debugging runbooks.

## Module Scope

Custom Drupal module:

- Export Pagedesigner element trees to JSON
- Import in two modes:
   - preserve: keep original IDs (surgical restore)
   - clone: create new IDs and remap references

Key files:

- src/Service/Exporter.php
- src/Service/Importer.php
- src/Service/IdMapper.php
- src/Commands/PagedesignerExportCommands.php
- pagedesigner_export.services.yml
- drush.services.yml

## Important Behavior

### Clone mode always creates new entities

Every clone import creates a full new element tree.
Repeated runs will accumulate additional trees.
This is expected behavior today.

### Preserve mode and ID safety

Preserve mode tries to create/update with original IDs. This can be sensitive in Drupal entity storage contexts.
Do not assume preserve mode is safe across arbitrary environments without collision checks and controlled conditions.

## Major Bug History and Fixes

### 1) Missing FR translations in exports

Symptom:

- FR content missing after import.

Root cause:

- Export traversal used one tree shape only.

Fix:

- Exporter now traverses per-language tree and unions all element IDs.

### 2) Import needed second run to fully work

Symptom:

- First run partially worked, second run fixed references.

Root cause:

- Preserve mode applied references before all elements existed.

Fix:

- Two-pass preserve import:
   - pass 1: create/update entities and non-structural fields
   - pass 2: apply container/parent/children references

### 3) Wrong default_langcode in export metadata

Symptom:

- Export JSON had default_langcode set to last iterated language (example: it).

Root cause:

- Loop variable shadowing in Exporter::export().

Fix:

- Preserve method argument as defaultLangcode and use that in exported metadata.

### 4) FR page empty on PR environment while DE worked

Symptom:

- Clone import succeeded, root updated for DE, FR rendered empty.
- Diagnostic output showed:
   - de root = new cloned root
   - fr root = old root

Root cause:

- In clone mode, target node field was updated on base translation only.
- Translated node field values (FR) kept stale root reference.

Fix:

- Importer now updates target field on base node and all available node translations.

### 5) Preserve mode imported elements with wrong base language — content invisible

Symptom:

- After preserve import of a single-language FR/IT export, the Pagedesigner editor showed the correct container structure but all content blocks were visually empty.
- Diagnostic showed root element had `base_lang=de`, `children (base): 0`, but `children (fr): 19`.

Root cause:

- `Element::create()` in `importElementPreserveIds()` was called without a `langcode` key.
- Drupal defaulted the base language to the site default (`de`).
- The exported FR/IT data was then stored as a non-default translation, but the structural `children` references written in pass 1 landed on the wrong translation slot. Pagedesigner renders from the base entity (0 children) → empty page.

Fix:

- Pass `'langcode' => $firstLang` to `Element::create()` in preserve mode.
- The element is now created with the export's language as its base, so all data (including children references) is stored in the correct translation slot from the start.

Context / when to use preserve vs overlay:

- Use **preserve** when each language has its own separate root element ID in the node field (e.g. `de→484928`, `fr→484929`, `it→484930`). The export file restores those IDs as standalone trees.
- Use **overlay** when all languages share the same root element ID and you want to add a translation to the shared tree.
- If unsure, check: `drush php:eval '$n=\Drupal\node\Entity\Node::load(NID); foreach(["de","fr","it"] as $l){$r=$n->hasTranslation($l)?$n->getTranslation($l)->get("FIELD")->target_id:"none"; echo "$l: $r\n";}'` — if all three print the same ID, use overlay; if different IDs, use preserve.

## Current Validation Additions

Importer now performs structural validation before write:

- root_id exists and is present in elements
- language codes in export exist on target site
- container/parent references point to existing exported elements
- children references point to existing exported elements

Clone reference fixing now:

- avoids unsafe language fallback behavior
- logs warning when child references cannot be mapped

## URL Sanitization

Option:

- --sanitize-local-urls (default true)

Behavior:

- Converts absolute local URLs to relative paths for portability.
- Targets localhost, 127.0.0.1, *.ddev.site hosts.

## Fast Diagnostics Runbook

### Verify node field points to same root in DE and FR

Use after clone import:

```bash
drush php:eval '$n=\Drupal\node\Entity\Node::load(516014); foreach(["de","fr"] as $lc){$t=$n->hasTranslation($lc)?$n->getTranslation($lc):$n; $rid=$t->get("field_pd_manual_content")->target_id; $e=\Drupal\pagedesigner\Entity\Element::load($rid); $et=($e&&$e->hasTranslation($lc))?$e->getTranslation($lc):$e; $count=$et?$et->get("children")->count():-1; echo $lc.": root=".$rid." children=".$count."\n"; }'
```

Expected after successful clone import:

- DE and FR should point to the same new root ID
- children count should be non-zero for both

### Check import logs

```bash
drush ws --count=200 | grep -i pagedesigner_export
```

### Check export JSON translation counts

```bash
python3 - << 'PY'
import json
p='app/private/pd-export/pd-root-141030.json'
d=json.load(open(p))
print('elements', len(d.get('elements',{})))
print('fr_elements', sum(1 for e in d.get('elements',{}).values() if 'fr' in e))
PY
```

## Operational Notes

- README.md is user-facing and workflow-oriented.
- This file is implementation-facing and incident-oriented.
- Keep both updated when behavior changes.

## Open Technical Debt / Follow-ups

1. Preserve mode semantics can still be clarified/hardened further.
2. Add stronger dry-run report output (currently validation errors throw early, but reporting can be more structured).
3. Consider optional cleanup strategy for old clone trees to avoid entity buildup.
4. Add automated integration tests for export/import with translations and reference remapping.

## Suggested Future Test Matrix

1. Clone import on node with DE+FR translations, verify both root pointers.
2. Export/import where tree shape differs by language.
3. Preserve mode with existing collisions and with free IDs.
4. Imports with sanitize-local-urls on/off.
5. Repeat clone imports on same node to verify expected accumulation behavior.
6. Overlay: single-language export → overlay onto existing tree → verify translations added.
7. Overlay: mismatched tree sizes (extra children in export vs target) → warnings logged.
8. Overlay: node translation field correctly updated to point to default container.

## Overlay Mode

### Purpose

Overlay mode (`--mode=overlay`) adds a single-language translation to an existing element tree. This addresses the "separate container per language" bug where each translation got its own container instead of sharing translations on the same elements.

### How it works

1. Export: Use `--only-langcode=fr` to export only the specified language's tree.
2. Import: Use `--mode=overlay --target-node=NID --target-field=FIELD --target-langcode=fr`.
3. The importer loads the target node's existing default-language tree.
4. It structurally maps exported elements → existing elements by tree position (root→root, children by index).
5. For each matched pair, it adds/updates the target language translation on the existing element.
6. Finally, it updates the node translation's pagedesigner field to point to the default container (fixing the separate-container-per-language issue).

### Limitations

- Tree matching is positional (by child order). If the exported tree structure diverges significantly from the target, results may be incomplete.
- Style references (`field_styles`) are also matched positionally.
- Mismatches log warnings but do not abort the import.

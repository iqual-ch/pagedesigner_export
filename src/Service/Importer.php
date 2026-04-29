<?php

namespace Drupal\pagedesigner_export\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\pagedesigner\Entity\Element;

/**
 * Imports Pagedesigner element trees from JSON.
 */
class Importer {

  /**
   * Constructs the Importer service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\pagedesigner_export\Service\IdMapper $idMapper
   *   The ID mapper service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected IdMapper $idMapper,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected LanguageManagerInterface $languageManager,
  ) {
  }

  /**
   * Import element tree from JSON data.
   *
   * @param array $data
   *   The export data array (or loaded from JSON file).
   * @param array $options
   *   Import options:
   *   - mode: 'preserve' (default) or 'clone' mode
   *   - target_nid: (required for 'clone' mode) Node ID to link the tree to
   *   - target_field: (required for 'clone' mode) Field name to update on target node
   *   - skip_existing: if TRUE, skip elements that already exist
   *   - dry_run: if TRUE, validate but don't write.
   *
   * @return array
   *   Result array with keys: success (bool), created_count, updated_count, errors (array), root_id (new)
   *
   * @throws \Exception
   *   On validation failures or when options are invalid.
   */
  public function import(array $data, array $options = []): array {
    $logger = $this->loggerFactory->get('pagedesigner_export');
    $this->idMapper->reset();

    // Validate options.
    $mode = $options['mode'] ?? 'preserve';
    $skipExisting = $options['skip_existing'] ?? FALSE;
    $dryRun = $options['dry_run'] ?? FALSE;
    $sanitizeLocalUrls = $options['sanitize_local_urls'] ?? TRUE;

    if (!in_array($mode, ['preserve', 'clone', 'overlay'])) {
      throw new \Exception("Invalid mode: {$mode}. Must be 'preserve', 'clone', or 'overlay'.");
    }

    if ($mode === 'clone') {
      $targetNid = $options['target_nid'] ?? NULL;
      $targetField = $options['target_field'] ?? NULL;
      if (!$targetNid || !$targetField) {
        throw new \Exception("Mode 'clone' requires 'target_nid' and 'target_field' options.");
      }

      $targetNode = $this->entityTypeManager->getStorage('node')->load($targetNid);
      if (!$targetNode instanceof NodeInterface) {
        throw new \Exception("Target node {$targetNid} not found.");
      }
      if (!$targetNode->hasField($targetField)) {
        throw new \Exception("Target node does not have field '{$targetField}'.");
      }
    }

    if ($mode === 'overlay') {
      $targetNid = $options['target_nid'] ?? NULL;
      $targetField = $options['target_field'] ?? NULL;
      $targetLangcode = $options['target_langcode'] ?? NULL;
      if (!$targetNid || !$targetField || !$targetLangcode) {
        throw new \Exception("Mode 'overlay' requires 'target_nid', 'target_field', and 'target_langcode' options.");
      }

      $targetNode = $this->entityTypeManager->getStorage('node')->load($targetNid);
      if (!$targetNode instanceof NodeInterface) {
        throw new \Exception("Target node {$targetNid} not found.");
      }
      if (!$targetNode->hasField($targetField)) {
        throw new \Exception("Target node does not have field '{$targetField}'.");
      }
    }

    // Validate data structure.
    if (empty($data['elements'])) {
      throw new \Exception("No elements found in export data.");
    }
    if (!array_key_exists('root_id', $data) || $data['root_id'] === NULL || $data['root_id'] === '') {
      throw new \Exception('Missing or invalid root_id in export data.');
    }

    $rootId = (string) $data['root_id'];
    $rootExists = FALSE;
    foreach (array_keys($data['elements']) as $elementId) {
      if ((string) $elementId === $rootId) {
        $rootExists = TRUE;
        break;
      }
    }
    if (!$rootExists) {
      throw new \Exception("Invalid root_id '{$rootId}': root element not found in elements.");
    }

    $validationErrors = $this->validateExportGraph($data);
    if (!empty($validationErrors)) {
      $sample = array_slice($validationErrors, 0, 10);
      throw new \Exception("Export validation failed:\n- " . implode("\n- ", $sample));
    }

    $result = [
      'success' => FALSE,
      'created_count' => 0,
      'updated_count' => 0,
      'errors' => [],
      'root_id' => NULL,
    ];

    if ($dryRun) {
      $logger->notice('DRY RUN: Validating ' . count($data['elements']) . ' elements...');
      if ($mode === 'overlay') {
        // Validate overlay mapping by building it without writing.
        $existingRootId = $this->getExistingRootId($targetNode, $targetField);
        $existingRoot = $this->entityTypeManager->getStorage('pagedesigner_element')->load($existingRootId);
        if (!$existingRoot) {
          throw new \Exception("Existing root element {$existingRootId} not found.");
        }
        $exportLangcode = $this->detectExportLangcode($data);
        $mapping = [];
        $this->buildTreeMapping($data, (int) $data['root_id'], $exportLangcode, $existingRoot, $targetNode->language()->getId(), $mapping, $logger);
        $logger->notice("Overlay mapping built: " . count($mapping) . " elements matched.");
      }
      $result['success'] = TRUE;
      return $result;
    }

    try {
      $elementIds = array_keys($data['elements']);
      // Simple ordering; could be improved.
      sort($elementIds);

      if ($mode === 'preserve') {
        // Two-pass import in preserve mode:
        // pass 1 creates/updates entities and non-structural fields,
        // pass 2 applies references (container/parent/children), ensuring all
        // referenced entities already exist.
        foreach ($elementIds as $oldElementId) {
          $elementData = $data['elements'][$oldElementId];

          try {
            $elementExists = $this->entityTypeManager->getStorage('pagedesigner_element')->load($oldElementId) !== NULL;
            $newElementId = $this->importElementPreserveIds($oldElementId, $elementData, $skipExisting, FALSE, $sanitizeLocalUrls);
            if ($newElementId !== NULL) {
              if ($elementExists) {
                $result['updated_count']++;
              }
              else {
                $result['created_count']++;
              }
            }
          }
          catch (\Exception $e) {
            $result['errors'][] = "Error importing element {$oldElementId}: " . $e->getMessage();
            $logger->error("Failed to import element {$oldElementId}: " . $e->getMessage());
          }
        }

        foreach ($elementIds as $oldElementId) {
          $elementData = $data['elements'][$oldElementId];
          try {
            $this->importElementPreserveIds($oldElementId, $elementData, $skipExisting, TRUE, $sanitizeLocalUrls);
          }
          catch (\Exception $e) {
            $result['errors'][] = "Error applying references for element {$oldElementId}: " . $e->getMessage();
            $logger->error("Failed to apply references for element {$oldElementId}: " . $e->getMessage());
          }
        }
      }
      elseif ($mode === 'clone') {
        if ($skipExisting) {
          $logger->notice('skip_existing is ignored in clone mode; clone mode always creates new elements.');
        }

        foreach ($elementIds as $oldElementId) {
          $elementData = $data['elements'][$oldElementId];

          try {
            $newElementId = $this->importElementClone($elementData, $sanitizeLocalUrls);
            if ($newElementId !== NULL) {
              $this->idMapper->addMapping((int) $oldElementId, $newElementId);
              $result['created_count']++;
            }
          }
          catch (\Exception $e) {
            $result['errors'][] = "Error importing element {$oldElementId}: " . $e->getMessage();
            $logger->error("Failed to import element {$oldElementId}: " . $e->getMessage());
          }
        }

        // Fix all references (children, styles) based on ID mappings.
        $this->fixReferencesAfterClone($data['elements']);

        // Update target node field to point to new root container.
        $oldRootId = (int) $data['root_id'];
        $newRootId = $this->idMapper->getNewId($oldRootId);
        if ($newRootId) {
          // Update the target field on the base node and all available
          // translations so translated pages do not keep stale root IDs.
          $targetNode->set($targetField, $newRootId);
          foreach ($targetNode->getTranslationLanguages() as $language) {
            $langcode = $language->getId();
            if ($langcode === $targetNode->language()->getId()) {
              continue;
            }
            if (!$targetNode->hasTranslation($langcode)) {
              continue;
            }
            $targetTranslation = $targetNode->getTranslation($langcode);
            if ($targetTranslation->hasField($targetField)) {
              $targetTranslation->set($targetField, $newRootId);
            }
          }
          $targetNode->save();
          $result['root_id'] = $newRootId;
          $logger->notice("Updated target node {$targetNid} field {$targetField} to new root {$newRootId}");
        }
      }
      elseif ($mode === 'overlay') {
        // Overlay mode: add a translation to existing elements by
        // structurally mapping the exported tree to the target tree.
        $result = $this->importOverlay($data, $targetLangcode, $targetNid, $targetField, $sanitizeLocalUrls, $targetNode);
      }

      if ($mode !== 'clone' && $mode !== 'overlay') {
        $result['root_id'] = $data['root_id'];
      }

      $result['success'] = TRUE;
      $logger->notice("Import complete. Created: " . $result['created_count'] . ", Updated: " . $result['updated_count'] . ", Errors: " . count($result['errors']));
    }
    catch (\Exception $e) {
      $result['errors'][] = $e->getMessage();
      $logger->error("Import failed: " . $e->getMessage());
    }

    return $result;
  }

  /**
   * Validate that the export graph is internally consistent.
   *
   * @param array $data
   *   The export data.
   *
   * @return array
   *   A list of validation error messages.
   */
  protected function validateExportGraph(array $data): array {
    $errors = [];
    $elements = $data['elements'] ?? [];
    $elementIds = array_fill_keys(array_map('strval', array_keys($elements)), TRUE);
    $enabledLanguages = array_fill_keys(array_keys($this->languageManager->getLanguages()), TRUE);

    foreach ($elements as $elementId => $translations) {
      if (!is_array($translations)) {
        $errors[] = "Element {$elementId} has invalid translation payload.";
        continue;
      }

      foreach ($translations as $langcode => $payload) {
        if (!isset($enabledLanguages[$langcode])) {
          $errors[] = "Element {$elementId} references missing language '{$langcode}' on target site.";
          continue;
        }

        if (!is_array($payload)) {
          $errors[] = "Element {$elementId} ({$langcode}) has invalid data payload.";
          continue;
        }

        foreach (['container', 'parent'] as $refField) {
          if (!array_key_exists($refField, $payload) || $payload[$refField] === NULL || $payload[$refField] === '') {
            continue;
          }

          $targetId = (string) $payload[$refField];
          if (!isset($elementIds[$targetId])) {
            $errors[] = "Element {$elementId} ({$langcode}) {$refField} references missing element {$targetId}.";
          }
        }

        if (isset($payload['children']) && is_array($payload['children'])) {
          foreach ($payload['children'] as $childRef) {
            if (!is_array($childRef) || !isset($childRef['target_id'])) {
              continue;
            }

            $targetId = (string) $childRef['target_id'];
            if (!isset($elementIds[$targetId])) {
              $errors[] = "Element {$elementId} ({$langcode}) child references missing element {$targetId}.";
            }
          }
        }
      }
    }

    return array_values(array_unique($errors));
  }

  /**
   * Import element in preserve-ids mode.
   *
   * @param int $elementId
   *   The element ID (will be preserved).
   * @param array $elementData
   *   The element data by language.
   * @param bool $skipExisting
   *   Skip if already exists.
   * @param bool $applyReferences
   *   Whether references (container/parent/children) should be applied.
   * @param bool $sanitizeLocalUrls
   *   Whether absolute local URLs should be converted to relative paths.
   *
   * @return int|null
   *   The element ID, or NULL if skipped.
   *
   * @throws \Exception
   */
  protected function importElementPreserveIds(int $elementId, array $elementData, bool $skipExisting, bool $applyReferences = TRUE, bool $sanitizeLocalUrls = TRUE): ?int {
    $storage = $this->entityTypeManager->getStorage('pagedesigner_element');

    // Check if exists.
    $existing = $storage->load($elementId);
    if ($existing) {
      if ($skipExisting) {
        return NULL;
      }
      // Update existing element.
      $element = $existing;
    }
    else {
      // Create new — but preserve the ID by using direct DB insert or entity with forced ID.
      // Drupal doesn't easily allow forcing IDs, so we'll do raw DB inserts.
      $element = NULL;
    }

    // Get one language's data to extract type and basic info.
    $firstLang = array_key_first($elementData);
    $firstData = $elementData[$firstLang];

    if (!$element) {
      // Create entity with forced ID using bundle-aware approach.
      $element = Element::create(
            [
              'type' => $firstData['type'],
              'name' => $firstData['name'],
              'status' => $firstData['status'] ?? TRUE,
            ]
        );
      // Force the ID before save.
      $element->set('id', $elementId);
    }

    // Import translations.
    foreach ($elementData as $langcode => $data) {
      if ($langcode === $firstLang && !$existing) {
        $translation = $element;
      }
      elseif ($langcode !== $element->language()->getId()) {
        if (!$element->hasTranslation($langcode)) {
          $element->addTranslation($langcode, $element->toArray());
        }
        $translation = $element->getTranslation($langcode);
      }
      else {
        $translation = $element;
      }

      $translation->set('type', $data['type']);
      $translation->set('name', $data['name']);
      $translation->set('status', $data['status'] ?? TRUE);

      if ($applyReferences && array_key_exists('container', $data)) {
        $translation->set('container', $data['container']);
      }
      if ($applyReferences && array_key_exists('parent', $data)) {
        $translation->set('parent', $data['parent']);
      }

      // Import custom fields.
      if (isset($data['fields'])) {
        foreach ($data['fields'] as $fieldName => $fieldValues) {
          if ($translation->hasField($fieldName)) {
            if ($sanitizeLocalUrls) {
              $fieldValues = $this->sanitizeFieldValues($fieldValues);
            }
            $translation->set($fieldName, $fieldValues);
          }
        }
      }

      if ($applyReferences && array_key_exists('children', $data)) {
        $translation->set('children', $data['children']);
      }
    }

    $element->save();
    return $element->id();
  }

  /**
   * Import element in clone mode (new IDs).
   *
   * @param array $elementData
   *   The element data by language.
   * @param bool $sanitizeLocalUrls
   *   Whether absolute local URLs should be converted to relative paths.
   *
   * @return int|null
   *   The new element ID.
   *
   * @throws \Exception
   */
  protected function importElementClone(array $elementData, bool $sanitizeLocalUrls = TRUE): ?int {
    $firstLang = array_key_first($elementData);
    $firstData = $elementData[$firstLang];

    $element = Element::create(
          [
            'type' => $firstData['type'],
            'name' => $firstData['name'],
            'status' => $firstData['status'] ?? TRUE,
          ]
      );

    // Import translations (will fix references after all elements created).
    foreach ($elementData as $langcode => $data) {
      if ($langcode !== $element->language()->getId()) {
        if (!$element->hasTranslation($langcode)) {
          $element->addTranslation($langcode, $element->toArray());
        }
        $translation = $element->getTranslation($langcode);
      }
      else {
        $translation = $element;
      }

      $translation->set('type', $data['type']);
      $translation->set('name', $data['name']);
      $translation->set('status', $data['status'] ?? TRUE);

      // Don't set container/parent/children yet — will be fixed in fixReferencesAfterClone().
      // But set custom fields.
      if (isset($data['fields'])) {
        foreach ($data['fields'] as $fieldName => $fieldValues) {
          if ($translation->hasField($fieldName)) {
            if ($sanitizeLocalUrls) {
              $fieldValues = $this->sanitizeFieldValues($fieldValues);
            }
            $translation->set($fieldName, $fieldValues);
          }
        }
      }
    }

    $element->save();
    return $element->id();
  }

  /**
   * Fix references (container, parent, children, styles) after cloning with new IDs.
   *
   * @param array $elementDataMap
   *   Map of old_id => [langcode => data].
   */
  protected function fixReferencesAfterClone(array $elementDataMap): void {
    $storage = $this->entityTypeManager->getStorage('pagedesigner_element');
    $logger = $this->loggerFactory->get('pagedesigner_export');

    foreach ($elementDataMap as $oldElementId => $elementData) {
      $newElementId = $this->idMapper->getNewId($oldElementId);
      if (!$newElementId) {
        $logger->warning("No mapping for old ID {$oldElementId}");
        continue;
      }

      /** @var \Drupal\pagedesigner\Entity\Element|null $element */
      $element = $storage->load($newElementId);
      if (!$element) {
        continue;
      }

      foreach ($elementData as $langcode => $data) {
        if ($langcode === $element->language()->getId()) {
          $translation = $element;
        }
        else {
          if (!$element->hasTranslation($langcode)) {
            try {
              $element->addTranslation($langcode, $element->toArray());
            }
            catch (\Exception $e) {
              $logger->warning("Skipping reference remap for element {$newElementId} lang {$langcode}: " . $e->getMessage());
              continue;
            }
          }
          $translation = $element->getTranslation($langcode);
        }

        // Fix container reference.
        if (array_key_exists('container', $data)) {
          if ($data['container'] === NULL || $data['container'] === '') {
            $translation->set('container', NULL);
          }
          else {
            $oldContainerId = (int) $data['container'];
            $newContainerId = $this->idMapper->getNewId($oldContainerId);
            $translation->set('container', $newContainerId);
          }
        }

        // Fix parent reference.
        if (array_key_exists('parent', $data)) {
          if ($data['parent'] === NULL || $data['parent'] === '') {
            $translation->set('parent', NULL);
          }
          else {
            $oldParentId = (int) $data['parent'];
            $newParentId = $this->idMapper->getNewId($oldParentId);
            $translation->set('parent', $newParentId);
          }
        }

        // Fix children references.
        if (isset($data['children']) && is_array($data['children'])) {
          $newChildren = [];
          $missingChildren = 0;
          foreach ($data['children'] as $childRef) {
            $oldChildId = $childRef['target_id'] ?? NULL;
            if (!$oldChildId) {
              continue;
            }
            $newChildId = $this->idMapper->getNewId($oldChildId);
            if ($newChildId) {
              $newChildren[] = ['target_id' => $newChildId];
            }
            else {
              $missingChildren++;
            }
          }

          if ($missingChildren > 0) {
            $logger->warning("Element {$newElementId} ({$langcode}) has {$missingChildren} unmapped child references during clone remap.");
          }
          $translation->set('children', $newChildren);
        }

        // Remap any pagedesigner element references in field payload.
        if (isset($data['fields']) && is_array($data['fields'])) {
          foreach ($data['fields'] as $fieldName => $fieldValues) {
            if (!$translation->hasField($fieldName)) {
              continue;
            }

            $definition = $translation->getFieldDefinition($fieldName);
            $isEntityReference = $definition->getType() === 'entity_reference';
            $targetType = $definition->getSetting('target_type');
            if (!$isEntityReference || $targetType !== 'pagedesigner_element') {
              continue;
            }

            $mappedValues = [];
            foreach ($fieldValues as $item) {
              $oldTargetId = isset($item['target_id']) ? (int) $item['target_id'] : 0;
              if (!$oldTargetId) {
                continue;
              }
              $newTargetId = $this->idMapper->getNewId($oldTargetId);
              if ($newTargetId) {
                $mappedValues[] = ['target_id' => $newTargetId];
              }
            }
            $translation->set($fieldName, $mappedValues);
          }
        }
      }

      $element->save();
    }
  }

  /**
   * Recursively sanitize field payload values.
   *
   * @param mixed $value
   *   The value to sanitize.
   *
   * @return mixed
   *   Sanitized value.
   */
  protected function sanitizeFieldValues(mixed $value): mixed {
    if (is_array($value)) {
      $sanitized = [];
      foreach ($value as $key => $item) {
        $sanitized[$key] = $this->sanitizeFieldValues($item);
      }

      if (isset($sanitized['attributes']) && is_array($sanitized['attributes']) && isset($sanitized['attributes']['href']) && is_string($sanitized['attributes']['href'])) {
        $sanitized['attributes']['href'] = $this->sanitizeLocalUrl($sanitized['attributes']['href']);
      }

      return $sanitized;
    }

    return $value;
  }

  /**
   * Convert absolute local URL to relative path.
   *
   * @param string $url
   *   The URL to sanitize.
   *
   * @return string
   *   The sanitized URL.
   */
  protected function sanitizeLocalUrl(string $url): string {
    $parts = parse_url($url);
    if ($parts === FALSE || empty($parts['host'])) {
      return $url;
    }

    $host = strtolower($parts['host']);
    $isLocalHost = $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.ddev.site');
    if (!$isLocalHost) {
      return $url;
    }

    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return $path . $query . $fragment;
  }

  /**
   * Import a single translation as an overlay onto an existing element tree.
   *
   * Structurally maps exported elements to existing elements on the target
   * node (by tree position), then adds/updates the target language translation
   * on each matched element. Finally updates the node translation's field to
   * point to the default container so the separate-per-language container
   * error is corrected.
   *
   * @param array $data
   *   The export data array.
   * @param string $targetLangcode
   *   The language code to create/update on existing elements.
   * @param int $targetNid
   *   The target node ID.
   * @param string $targetField
   *   The pagedesigner field name on the target node.
   * @param bool $sanitizeLocalUrls
   *   Whether absolute local URLs should be converted to relative paths.
   * @param \Drupal\node\NodeInterface $targetNode
   *   The already-loaded target node.
   *
   * @return array
   *   Result array with keys: success, created_count, updated_count, errors, root_id.
   *
   * @throws \Exception
   */
  protected function importOverlay(array $data, string $targetLangcode, int $targetNid, string $targetField, bool $sanitizeLocalUrls, NodeInterface $targetNode): array {
    $storage = $this->entityTypeManager->getStorage('pagedesigner_element');
    $logger = $this->loggerFactory->get('pagedesigner_export');

    $result = [
      'success' => FALSE,
      'created_count' => 0,
      'updated_count' => 0,
      'errors' => [],
      'root_id' => NULL,
    ];

    // Determine the existing root container from the node's default language.
    $existingRootId = $this->getExistingRootId($targetNode, $targetField);

    /** @var \Drupal\pagedesigner\Entity\Element|null $existingRoot */
    $existingRoot = $storage->load($existingRootId);
    if (!$existingRoot) {
      throw new \Exception("Existing root element {$existingRootId} not found.");
    }

    // Detect the language used in the export.
    $exportLangcode = $this->detectExportLangcode($data);
    $defaultLangcode = $targetNode->language()->getId();

    $logger->notice("Overlay: mapping exported tree (lang: {$exportLangcode}) onto existing tree (root: {$existingRootId}) as '{$targetLangcode}' translation.");

    // Build a structural mapping: exported_element_id => existing_element_id.
    $mapping = [];
    $this->buildTreeMapping($data, (int) $data['root_id'], $exportLangcode, $existingRoot, $defaultLangcode, $mapping, $logger);

    $logger->notice("Overlay: matched " . count($mapping) . " elements.");

    // Apply translations using the mapping.
    foreach ($mapping as $exportedId => $existingId) {
      $exportedData = $data['elements'][$exportedId] ?? NULL;
      if (!$exportedData) {
        continue;
      }

      $langData = $exportedData[$exportLangcode] ?? NULL;
      if (!$langData) {
        continue;
      }

      /** @var \Drupal\pagedesigner\Entity\Element|null $element */
      $element = $storage->load($existingId);
      if (!$element) {
        $result['errors'][] = "Could not load existing element {$existingId}.";
        continue;
      }

      try {
        // Add or update translation.
        if ($element->hasTranslation($targetLangcode)) {
          $translation = $element->getTranslation($targetLangcode);
        }
        else {
          $element->addTranslation($targetLangcode, $element->toArray());
          $translation = $element->getTranslation($targetLangcode);
        }

        $translation->set('name', $langData['name']);
        $translation->set('status', $langData['status'] ?? TRUE);

        // Set container reference using the mapping.
        if (array_key_exists('container', $langData)) {
          if ($langData['container'] === NULL || $langData['container'] === '') {
            $translation->set('container', NULL);
          }
          else {
            $mappedContainer = $mapping[(int) $langData['container']] ?? NULL;
            if ($mappedContainer) {
              $translation->set('container', $mappedContainer);
            }
          }
        }

        // Set parent reference using the mapping.
        if (array_key_exists('parent', $langData)) {
          if ($langData['parent'] === NULL || $langData['parent'] === '') {
            $translation->set('parent', NULL);
          }
          else {
            $mappedParent = $mapping[(int) $langData['parent']] ?? NULL;
            if ($mappedParent) {
              $translation->set('parent', $mappedParent);
            }
          }
        }

        // Set children references using the mapping.
        if (isset($langData['children']) && is_array($langData['children'])) {
          $newChildren = [];
          foreach ($langData['children'] as $childRef) {
            $oldChildId = $childRef['target_id'] ?? NULL;
            if ($oldChildId && isset($mapping[(int) $oldChildId])) {
              $newChildren[] = ['target_id' => $mapping[(int) $oldChildId]];
            }
          }
          $translation->set('children', $newChildren);
        }

        // Set custom fields.
        if (isset($langData['fields'])) {
          foreach ($langData['fields'] as $fieldName => $fieldValues) {
            if (!$translation->hasField($fieldName)) {
              continue;
            }

            if ($sanitizeLocalUrls) {
              $fieldValues = $this->sanitizeFieldValues($fieldValues);
            }

            // Remap entity references to pagedesigner elements.
            $definition = $translation->getFieldDefinition($fieldName);
            if ($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'pagedesigner_element') {
              $mappedValues = [];
              foreach ($fieldValues as $item) {
                $oldTargetId = isset($item['target_id']) ? (int) $item['target_id'] : 0;
                if ($oldTargetId && isset($mapping[$oldTargetId])) {
                  $mappedValues[] = ['target_id' => $mapping[$oldTargetId]];
                }
              }
              $fieldValues = $mappedValues;
            }

            $translation->set($fieldName, $fieldValues);
          }
        }

        $element->save();
        $result['updated_count']++;
      }
      catch (\Exception $e) {
        $result['errors'][] = "Error overlaying element {$existingId} (from exported {$exportedId}): " . $e->getMessage();
        $logger->error("Overlay failed for element {$existingId}: " . $e->getMessage());
      }
    }

    // Update the node's translation to point to the default container
    // (fixing the separate-container-per-language error).
    if ($targetNode->hasTranslation($targetLangcode)) {
      $nodeTranslation = $targetNode->getTranslation($targetLangcode);
      if ($nodeTranslation->hasField($targetField)) {
        $nodeTranslation->set($targetField, $existingRootId);
        $targetNode->save();
        $logger->notice("Updated node {$targetNid} ({$targetLangcode}) field {$targetField} to point to default container {$existingRootId}.");
      }
    }
    else {
      $logger->warning("Node {$targetNid} has no '{$targetLangcode}' translation; node field not updated.");
    }

    $result['root_id'] = $existingRootId;
    $result['success'] = TRUE;
    $logger->notice("Overlay complete. Updated: {$result['updated_count']} translations, Errors: " . count($result['errors']));

    return $result;
  }

  /**
   * Get the existing root element ID from a node's pagedesigner field.
   *
   * Uses the node's default language to find the "real" container.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The target node.
   * @param string $fieldName
   *   The pagedesigner field name.
   *
   * @return int
   *   The root element ID.
   *
   * @throws \Exception
   *   If the field is empty.
   */
  protected function getExistingRootId(NodeInterface $node, string $fieldName): int {
    $defaultLangcode = $node->language()->getId();
    $baseNode = $node->hasTranslation($defaultLangcode) ? $node->getTranslation($defaultLangcode) : $node;

    $rootId = $baseNode->get($fieldName)->target_id ?? NULL;
    if (!$rootId) {
      throw new \Exception("Target node {$node->id()} has no element in field '{$fieldName}' (default language: {$defaultLangcode}).");
    }

    return (int) $rootId;
  }

  /**
   * Detect the language used in the export data.
   *
   * For single-language exports, returns the only_langcode metadata or the
   * single language present. For multi-language exports, returns the
   * default_langcode.
   *
   * @param array $data
   *   The export data array.
   *
   * @return string
   *   The detected language code.
   *
   * @throws \Exception
   *   If no language can be determined.
   */
  protected function detectExportLangcode(array $data): string {
    // Single-language export metadata.
    if (!empty($data['only_langcode'])) {
      return $data['only_langcode'];
    }

    // Inspect the root element to find available languages.
    $rootId = (string) $data['root_id'];
    $rootData = $data['elements'][$rootId] ?? [];
    $languages = array_keys($rootData);

    if (count($languages) === 1) {
      return $languages[0];
    }

    // Fall back to default_langcode.
    if (!empty($data['default_langcode'])) {
      return $data['default_langcode'];
    }

    throw new \Exception("Cannot determine export language for overlay. Use --only-langcode during export or ensure default_langcode is set.");
  }

  /**
   * Build a structural mapping between exported element IDs and existing IDs.
   *
   * Walks both trees in parallel (by children order), mapping exported
   * elements to existing elements positionally. Also maps style references.
   *
   * @param array $data
   *   The full export data with elements.
   * @param int $exportedId
   *   The current exported element ID being mapped.
   * @param string $exportLangcode
   *   The language code to read children from in the export.
   * @param \Drupal\pagedesigner\Entity\Element $existingElement
   *   The corresponding existing element on the target site.
   * @param string $existingLangcode
   *   The language code to read children from on the existing element.
   * @param array $mapping
   *   Reference to the mapping array being built; [exported_id => existing_id].
   * @param object $logger
   *   Logger channel.
   */
  protected function buildTreeMapping(array $data, int $exportedId, string $exportLangcode, Element $existingElement, string $existingLangcode, array &$mapping, $logger): void {
    $mapping[$exportedId] = (int) $existingElement->id();

    $exportedData = $data['elements'][$exportedId][$exportLangcode] ?? [];

    // Get existing element in its default/specified language.
    if ($existingElement->hasTranslation($existingLangcode)) {
      $existingTranslation = $existingElement->getTranslation($existingLangcode);
    }
    else {
      $existingTranslation = $existingElement;
    }

    // Map children by position.
    $exportedChildren = $exportedData['children'] ?? [];
    $existingChildren = [];
    if ($existingTranslation->hasField('children')) {
      foreach ($existingTranslation->get('children') as $childRef) {
        if ($childRef->entity) {
          $existingChildren[] = $childRef->entity;
        }
      }
    }

    $matchCount = min(count($exportedChildren), count($existingChildren));
    if (count($exportedChildren) !== count($existingChildren)) {
      $logger->warning("Overlay mapping: exported element {$exportedId} has " . count($exportedChildren) . " children but existing element " . $existingElement->id() . " has " . count($existingChildren) . ". Matching first {$matchCount}.");
    }

    for ($i = 0; $i < $matchCount; $i++) {
      $exportedChildId = (int) ($exportedChildren[$i]['target_id'] ?? 0);
      if (!$exportedChildId || !isset($data['elements'][$exportedChildId])) {
        continue;
      }
      $this->buildTreeMapping($data, $exportedChildId, $exportLangcode, $existingChildren[$i], $existingLangcode, $mapping, $logger);
    }

    // Map style references by position.
    $exportedStyles = $exportedData['fields']['field_styles'] ?? [];
    $existingStyles = [];
    if ($existingTranslation->hasField('field_styles')) {
      foreach ($existingTranslation->get('field_styles') as $styleRef) {
        if ($styleRef->entity) {
          $existingStyles[] = $styleRef->entity;
        }
      }
    }

    $styleMatchCount = min(count($exportedStyles), count($existingStyles));
    if (count($exportedStyles) !== count($existingStyles) && (count($exportedStyles) > 0 || count($existingStyles) > 0)) {
      $logger->warning("Overlay mapping: exported element {$exportedId} has " . count($exportedStyles) . " style refs but existing element " . $existingElement->id() . " has " . count($existingStyles) . ". Matching first {$styleMatchCount}.");
    }

    for ($i = 0; $i < $styleMatchCount; $i++) {
      $exportedStyleId = (int) ($exportedStyles[$i]['target_id'] ?? 0);
      if (!$exportedStyleId || !isset($data['elements'][$exportedStyleId])) {
        continue;
      }
      $this->buildTreeMapping($data, $exportedStyleId, $exportLangcode, $existingStyles[$i], $existingLangcode, $mapping, $logger);
    }
  }

}

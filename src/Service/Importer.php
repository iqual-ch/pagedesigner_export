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

    if (!in_array($mode, ['preserve', 'clone'])) {
      throw new \Exception("Invalid mode: {$mode}. Must be 'preserve' or 'clone'.");
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
      // Just validate structure, don't import.
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
      else {
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

      if ($mode !== 'clone') {
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

}

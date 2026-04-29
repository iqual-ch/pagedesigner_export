<?php

namespace Drupal\pagedesigner_export\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\pagedesigner\Entity\Element;

/**
 * Exports Pagedesigner element trees to JSON.
 */
class Exporter {

  /**
   * Constructs the Exporter service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Export a pagedesigner element tree and all (or one) translations.
   *
   * @param int $elementId
   *   The root element ID to export.
   * @param string $langcode
   *   The default language code (will include all translations unless
   *   $onlyLangcode is set).
   * @param bool $sanitizeLocalUrls
   *   Whether absolute local URLs should be converted to relative paths.
   * @param string|null $onlyLangcode
   *   If set, export only this single language per element. The tree is
   *   traversed using this language's children structure. Useful for
   *   single-translation overlay workflows.
   *
   * @return array
   *   Structured export data containing all elements and translations.
   *
   * @throws \Exception
   *   If element cannot be loaded.
   */
  public function export(int $elementId, string $langcode = 'de', bool $sanitizeLocalUrls = TRUE, ?string $onlyLangcode = NULL): array {
    /** @var \Drupal\pagedesigner\Entity\Element $root */
    $root = $this->entityTypeManager->getStorage('pagedesigner_element')->load($elementId);
    if (!$root) {
      throw new \Exception("Cannot load pagedesigner_element {$elementId}");
    }

    $logger = $this->loggerFactory->get('pagedesigner_export');
    $defaultLangcode = $langcode;

    if ($onlyLangcode) {
      // Single-language export: traverse only the specified language's tree.
      if (!$root->hasTranslation($onlyLangcode)) {
        throw new \Exception("Root element {$elementId} has no translation for '{$onlyLangcode}'.");
      }
      $visited = [];
      $elementIds = $this->collectElementIds($root, $onlyLangcode, $visited);
      $elementIds = array_values(array_unique($elementIds));
      $languagesToExport = [$onlyLangcode];
      $logger->notice("Exporting " . count($elementIds) . " elements from tree (root: {$elementId}, language: {$onlyLangcode}).");
    }
    else {
      // Full export: traverse all languages and union element IDs.
      $allLanguages = array_keys($this->languageManager->getLanguages());
      $elementIds = [];
      foreach ($allLanguages as $lang) {
        if ($root->hasTranslation($lang)) {
          $visited = [];
          $elementIds = array_merge($elementIds, $this->collectElementIds($root, $lang, $visited));
        }
      }
      $elementIds = array_values(array_unique($elementIds));
      $languagesToExport = $allLanguages;
      $logger->notice("Exporting " . count($elementIds) . " elements from tree (root: {$elementId}).");
    }

    // Export each element with the appropriate translations.
    $data = [
      'root_id' => $elementId,
      'default_langcode' => $defaultLangcode,
      'exported_at' => time(),
      'elements' => [],
    ];

    if ($onlyLangcode) {
      $data['only_langcode'] = $onlyLangcode;
    }

    foreach ($elementIds as $eid) {
      /** @var \Drupal\pagedesigner\Entity\Element $element */
      $element = $this->entityTypeManager->getStorage('pagedesigner_element')->load($eid);
      if (!$element) {
        $logger->warning("Could not load element " . $eid);
        continue;
      }

      $elementData = [];

      // Export each requested translation.
      foreach ($languagesToExport as $lang) {
        if ($element->hasTranslation($lang)) {
          $translation = $element->getTranslation($lang);
          $elementData[$lang] = $this->exportTranslation($translation, $sanitizeLocalUrls);
        }
      }

      if ($elementData) {
        $data['elements'][$eid] = $elementData;
      }
    }

    $logger->notice('Exported ' . count($data['elements']) . ' elements.');
    return $data;
  }

  /**
   * Export a single element translation.
   *
   * @param \Drupal\pagedesigner\Entity\Element $element
   *   The element translation to export.
   * @param bool $sanitizeLocalUrls
   *   Whether absolute local URLs should be converted to relative paths.
   *
   * @return array
   *   The element data.
   */
  protected function exportTranslation(Element $element, bool $sanitizeLocalUrls = TRUE): array {
    $data = [
      'type' => $element->bundle(),
      'name' => $element->get('name')->value,
      'status' => (bool) $element->get('status')->value,
      'langcode' => $element->language()->getId(),
    ];

    // Export base structure fields explicitly, including empty values.
    if ($element->hasField('container')) {
      $data['container'] = $element->get('container')->target_id ?? NULL;
    }
    if ($element->hasField('parent')) {
      $data['parent'] = $element->get('parent')->target_id ?? NULL;
    }

    // Export all configurable fields.
    $fieldDefinitions = $element->getFieldDefinitions();
    $skipFields = [
      'id',
      'vid',
      'uuid',
      'type',
      'user_id',
      'name',
      'status',
      'created',
      'changed',
      'revision_translation_affected',
      'entity',
      'container',
      'parent',
      'children',
      'deleted',
      'default_langcode',
      'content_translation_source',
      'content_translation_outdated',
      'content_translation_uid',
      'content_translation_created',
      'content_translation_changed',
      'langcode',
    ];

    foreach ($fieldDefinitions as $fieldName => $definition) {
      if (in_array($fieldName, $skipFields)) {
        continue;
      }

      if ($element->hasField($fieldName)) {
        $values = $element->get($fieldName)->getValue();
        if (!empty($values)) {
          if ($sanitizeLocalUrls) {
            $values = $this->sanitizeFieldValues($values);
          }
          $data['fields'][$fieldName] = $values;
        }
      }
    }

    // Export children references.
    if ($element->hasField('children')) {
      $childrenValues = $element->get('children')->getValue();
      $data['children'] = array_map(static function ($item) {
        return [
          'target_id' => $item['target_id'],
        ];
      }, $childrenValues ?? []);
    }

    return $data;
  }

  /**
   * Collect all element IDs in a language-specific tree.
   *
   * @param \Drupal\pagedesigner\Entity\Element $element
   *   The root element.
   * @param string $langcode
   *   The language code to traverse.
   * @param array $visited
   *   Internal visited map to avoid loops.
   *
   * @return array
   *   Array of element IDs in the tree.
   */
  protected function collectElementIds(Element $element, string $langcode, array &$visited = []): array {
    // Always traverse the language-specific tree when translation exists.
    if ($element->hasTranslation($langcode)) {
      $element = $element->getTranslation($langcode);
    }

    $id = (int) $element->id();
    if (isset($visited[$id])) {
      return [];
    }
    $visited[$id] = TRUE;

    $ids = [$id];

    if ($element->hasField('children')) {
      foreach ($element->get('children') as $childRef) {
        if (!$childRef->entity) {
          continue;
        }
        $ids = array_merge($ids, $this->collectElementIds($childRef->entity, $langcode, $visited));
      }
    }

    // Also collect style references.
    if ($element->hasField('field_styles')) {
      foreach ($element->get('field_styles') as $styleRef) {
        if (!$styleRef->entity) {
          continue;
        }
        $ids = array_merge($ids, $this->collectElementIds($styleRef->entity, $langcode, $visited));
      }
    }

    return $ids;
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

<?php

namespace Drupal\pagedesigner_export\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\path_alias\AliasManagerInterface;
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
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected ConfigFactoryInterface $configFactory,
    protected AliasManagerInterface $aliasManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Export contract schema version.
   */
  public const MIGRATION_SCHEMA_VERSION = '0.1.0';

  /**
   * Build a migration manifest for source entities with pagedesigner roots.
   *
   * @param array $options
   *   Manifest options:
   *   - entity_type: Entity type ID, defaults to node.
   *   - bundle: Optional bundle filter.
   *   - field: Optional pagedesigner field filter.
   *   - limit: Optional page/root limit.
   *   - include_unpublished: Include unpublished page translations.
   *
   * @return array
   *   The manifest data.
   */
  public function buildMigrationManifest(array $options = []): array {
    $siteConfig = $this->configFactory->get('system.site');
    $entityTypeId = (string) ($options['entity_type'] ?? 'node');

    return [
      'schema_version' => self::MIGRATION_SCHEMA_VERSION,
      'source' => [
        'adapter' => 'pagedesigner_export',
        'site_name' => $siteConfig->get('name') ?: NULL,
        'site_uuid' => $siteConfig->get('uuid') ?: NULL,
        'base_url' => $this->getBaseUrl(),
        'default_langcode' => $this->languageManager->getDefaultLanguage()->getId(),
        'drupal_version' => \Drupal::VERSION,
        'exported_at' => time(),
        'module_version' => self::MIGRATION_SCHEMA_VERSION,
      ],
      'pages' => $this->discoverMigrationPages($entityTypeId, $options),
    ];
  }

  /**
   * Export a complete migration package with manifest.json and pages/*.json.
   *
   * @param string $outputDirectory
   *   Output package directory.
   * @param array $options
   *   Export options. See buildMigrationManifest().
   *
   * @return array
   *   Export result with manifest data and page count.
   *
   * @throws \Exception
   *   If any tree cannot be exported or written.
   */
  public function exportMigrationPackage(string $outputDirectory, array $options = []): array {
    $manifest = $this->buildMigrationManifest($options);
    $sanitizeLocalUrls = (bool) ($options['sanitize_local_urls'] ?? TRUE);

    $pagesDirectory = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pages';
    if (!is_dir($pagesDirectory) && !mkdir($pagesDirectory, 0775, TRUE) && !is_dir($pagesDirectory)) {
      throw new \Exception("Failed to create pages directory: {$pagesDirectory}");
    }

    foreach ($manifest['pages'] as $page) {
      $tree = $this->export((int) $page['pagedesigner_root_id'], $page['default_langcode'], $sanitizeLocalUrls);
      $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . $page['export_file'], $tree);
    }

    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

    return [
      'manifest' => $manifest,
      'page_count' => count($manifest['pages']),
    ];
  }

  /**
   * Export a pagedesigner element tree and all translations.
   *
   * @param int $elementId
   *   The root element ID to export.
   * @param string $langcode
   *   The default language code (will include all translations).
   * @param bool $sanitizeLocalUrls
   *   Whether absolute local URLs should be converted to relative paths.
   *
   * @return array
   *   Structured export data containing all elements and translations.
   *
   * @throws \Exception
   *   If element cannot be loaded.
   */
  public function export(int $elementId, string $langcode = 'de', bool $sanitizeLocalUrls = TRUE): array {
    /** @var \Drupal\pagedesigner\Entity\Element $root */
    $root = $this->entityTypeManager->getStorage('pagedesigner_element')->load($elementId);
    if (!$root) {
      throw new \Exception("Cannot load pagedesigner_element {$elementId}");
    }

    $logger = $this->loggerFactory->get('pagedesigner_export');
    $allLanguages = array_keys($this->languageManager->getLanguages());
    $defaultLangcode = $langcode;

    // Build a map of all element IDs in the tree for all available languages.
    $elementIds = [];
    foreach ($allLanguages as $langcode) {
      if ($root->hasTranslation($langcode)) {
        $visited = [];
        $elementIds = array_merge($elementIds, $this->collectElementIds($root, $langcode, $visited));
      }
    }
    $elementIds = array_values(array_unique($elementIds));
    $logger->notice("Exporting " . count($elementIds) . " elements from tree (root: " . $elementId . ").");

    // Export each element with all its translations.
    $data = [
      'root_id' => $elementId,
      'default_langcode' => $defaultLangcode,
      'exported_at' => time(),
      'elements' => [],
    ];

    foreach ($elementIds as $eid) {
      /** @var \Drupal\pagedesigner\Entity\Element $element */
      $element = $this->entityTypeManager->getStorage('pagedesigner_element')->load($eid);
      if (!$element) {
        $logger->warning("Could not load element " . $eid);
        continue;
      }

      $elementData = [];

      // Export each translation.
      foreach ($allLanguages as $lang) {
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
   * Discover source pages/entities that contain pagedesigner root references.
   *
   * @param string $entityTypeId
   *   Content entity type ID.
   * @param array $options
   *   Discovery options.
   *
   * @return array
   *   Manifest page entries.
   *
   * @throws \Exception
   *   If the entity type cannot be exported.
   */
  protected function discoverMigrationPages(string $entityTypeId, array $options): array {
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if (!$entityType) {
      throw new \Exception("Unknown entity type: {$entityTypeId}");
    }
    if (!$entityType->entityClassImplements(ContentEntityInterface::class)) {
      throw new \Exception("Entity type is not a content entity: {$entityTypeId}");
    }

    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $bundleKey = $entityType->getKey('bundle');
    $idKey = $entityType->getKey('id') ?: 'id';
    $bundleFilter = $options['bundle'] ?? NULL;
    $fieldFilter = $options['field'] ?? NULL;
    $includeUnpublished = (bool) ($options['include_unpublished'] ?? FALSE);
    $limit = isset($options['limit']) && $options['limit'] !== NULL && $options['limit'] !== '' ? (int) $options['limit'] : NULL;
    $bundles = $this->getBundles($entityTypeId, $bundleFilter);
    $pages = [];

    foreach ($bundles as $bundle) {
      $pagedesignerFields = $this->getPagedesignerFields($entityTypeId, $bundle, $fieldFilter);
      if (!$pagedesignerFields) {
        continue;
      }

      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->sort($idKey, 'ASC');

      if ($bundleKey && $bundle !== $entityTypeId) {
        $query->condition($bundleKey, $bundle);
      }

      $fieldGroup = $query->orConditionGroup();
      foreach ($pagedesignerFields as $fieldName) {
        $fieldGroup->exists($fieldName);
      }
      $query->condition($fieldGroup);

      $remaining = $limit !== NULL ? $limit - count($pages) : NULL;
      if ($remaining !== NULL && $remaining <= 0) {
        break;
      }
      if ($remaining !== NULL) {
        $query->range(0, $remaining);
      }

      $entityIds = $query->execute();
      if (!$entityIds) {
        continue;
      }

      /** @var \Drupal\Core\Entity\ContentEntityInterface[] $entities */
      $entities = $storage->loadMultiple($entityIds);
      foreach ($entities as $entity) {
        $langcodes = $this->getExportableLangcodes($entity, $includeUnpublished);
        if (!$langcodes) {
          continue;
        }

        $rootsByField = $this->getPagedesignerRootsByField($entity, $pagedesignerFields, $langcodes);
        $rootCount = array_sum(array_map('count', $rootsByField));
        foreach ($rootsByField as $fieldName => $rootIds) {
          foreach ($rootIds as $rootId) {
            $pages[] = $this->buildPageManifestEntry($entity, $fieldName, (string) $rootId, $langcodes, $rootCount > 1);
            if ($limit !== NULL && count($pages) >= $limit) {
              break 3;
            }
          }
        }
      }
    }

    return $pages;
  }

  /**
   * Get bundle IDs for an entity type.
   *
   * @param string $entityTypeId
   *   Entity type ID.
   * @param string|null $bundleFilter
   *   Optional bundle filter.
   *
   * @return string[]
   *   Bundle IDs.
   */
  protected function getBundles(string $entityTypeId, ?string $bundleFilter): array {
    if ($bundleFilter) {
      return [$bundleFilter];
    }

    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
    return $bundleInfo ? array_keys($bundleInfo) : [$entityTypeId];
  }

  /**
   * Get pagedesigner entity reference fields for a bundle.
   *
   * @param string $entityTypeId
   *   Entity type ID.
   * @param string $bundle
   *   Bundle ID.
   * @param string|null $fieldFilter
   *   Optional field filter.
   *
   * @return string[]
   *   Field names.
   */
  protected function getPagedesignerFields(string $entityTypeId, string $bundle, ?string $fieldFilter): array {
    $fields = [];
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
    foreach ($definitions as $fieldName => $definition) {
      if ($fieldFilter && $fieldName !== $fieldFilter) {
        continue;
      }
      if (in_array($definition->getType(), ['entity_reference', 'entity_reference_revisions'], TRUE) && $definition->getSetting('target_type') === 'pagedesigner_element') {
        $fields[] = $fieldName;
      }
    }

    return $fields;
  }

  /**
   * Get exportable language codes for an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   * @param bool $includeUnpublished
   *   Whether unpublished translations are included.
   *
   * @return string[]
   *   Language codes.
   */
  protected function getExportableLangcodes(ContentEntityInterface $entity, bool $includeUnpublished): array {
    $defaultLangcode = $entity->language()->getId();
    $languages = [$defaultLangcode => $defaultLangcode];
    if ($entity->isTranslatable()) {
      foreach ($entity->getTranslationLanguages() as $langcode => $language) {
        $languages[$langcode] = $langcode;
      }
    }

    $exportable = [];
    foreach ($languages as $langcode) {
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      if (!$includeUnpublished && $translation instanceof EntityPublishedInterface && !$translation->isPublished()) {
        continue;
      }
      $exportable[] = $langcode;
    }

    usort($exportable, static function (string $a, string $b) use ($defaultLangcode): int {
      if ($a === $defaultLangcode) {
        return -1;
      }
      if ($b === $defaultLangcode) {
        return 1;
      }
      return $a <=> $b;
    });

    return array_values(array_unique($exportable));
  }

  /**
   * Collect pagedesigner root IDs grouped by field for exportable translations.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   * @param string[] $fieldNames
   *   Candidate pagedesigner fields.
   * @param string[] $langcodes
   *   Exportable language codes.
   *
   * @return array
   *   Root IDs keyed by field name.
   */
  protected function getPagedesignerRootsByField(ContentEntityInterface $entity, array $fieldNames, array $langcodes): array {
    $rootsByField = [];
    foreach ($fieldNames as $fieldName) {
      $rootIds = [];
      foreach ($langcodes as $langcode) {
        $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
        if (!$translation->hasField($fieldName) || $translation->get($fieldName)->isEmpty()) {
          continue;
        }
        foreach ($translation->get($fieldName)->getValue() as $item) {
          if (!empty($item['target_id'])) {
            $rootIds[(string) $item['target_id']] = (string) $item['target_id'];
          }
        }
      }
      if ($rootIds) {
        $rootsByField[$fieldName] = array_values($rootIds);
      }
    }

    return $rootsByField;
  }

  /**
   * Build a manifest page entry for one source entity/root pair.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   * @param string $fieldName
   *   Pagedesigner field name.
   * @param string $rootId
   *   Pagedesigner root element ID.
   * @param string[] $langcodes
   *   Exported language codes.
   * @param bool $forceUniqueId
   *   Whether the page ID should include field/root details.
   *
   * @return array
   *   Manifest page entry.
   */
  protected function buildPageManifestEntry(ContentEntityInterface $entity, string $fieldName, string $rootId, array $langcodes, bool $forceUniqueId): array {
    $entityTypeId = $entity->getEntityTypeId();
    $entityId = (string) $entity->id();
    $defaultLangcode = $entity->language()->getId();
    $titles = [];
    $paths = [];
    $statuses = [];

    foreach ($langcodes as $langcode) {
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      $titles[$langcode] = $translation->label();
      $paths[$langcode] = $this->getEntityPath($translation, $langcode);
      $statuses[$langcode] = $translation instanceof EntityPublishedInterface ? $translation->isPublished() : TRUE;
    }

    $pageId = "{$entityTypeId}:{$entityId}";
    if ($forceUniqueId) {
      $pageId .= ":{$fieldName}:root:{$rootId}";
    }

    $entry = [
      'id' => $pageId,
      'entity_type' => $entityTypeId,
      'bundle' => $entity->bundle(),
      'source_entity_id' => is_numeric($entityId) ? (int) $entityId : $entityId,
      'entity_id' => is_numeric($entityId) ? (int) $entityId : $entityId,
      'default_langcode' => $defaultLangcode,
      'languages' => $langcodes,
      'langcodes' => $langcodes,
      'titles' => $titles,
      'title' => $titles,
      'paths' => $paths,
      'path' => $paths,
      'status' => $statuses[$defaultLangcode] ?? reset($statuses),
      'statuses' => $statuses,
      'pagedesigner_root_id' => $rootId,
      'pagedesigner_field' => $fieldName,
      'export_file' => 'pages/' . $this->buildPageExportFilename($entityTypeId, $entityId, $fieldName, $rootId, $forceUniqueId),
    ];

    if (method_exists($entity, 'uuid')) {
      $entry['uuid'] = $entity->uuid();
    }

    return $entry;
  }

  /**
   * Build a stable page tree export filename.
   *
   * @param string $entityTypeId
   *   Entity type ID.
   * @param string $entityId
   *   Entity ID.
   * @param string $fieldName
   *   Pagedesigner field name.
   * @param string $rootId
   *   Root element ID.
   * @param bool $includeField
   *   Include the field name in the filename.
   *
   * @return string
   *   Filename relative to pages/.
   */
  protected function buildPageExportFilename(string $entityTypeId, string $entityId, string $fieldName, string $rootId, bool $includeField): string {
    $parts = [$entityTypeId, $entityId];
    if ($includeField) {
      $parts[] = $fieldName;
    }
    $parts[] = 'root';
    $parts[] = $rootId;

    return preg_replace('/[^A-Za-z0-9_.-]+/', '-', implode('-', $parts)) . '.json';
  }

  /**
   * Get a source entity path/alias for a language.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity translation.
   * @param string $langcode
   *   Language code.
   *
   * @return string|null
   *   Path or alias, if available.
   */
  protected function getEntityPath(ContentEntityInterface $entity, string $langcode): ?string {
    if ($entity->getEntityTypeId() === 'node') {
      return $this->aliasManager->getAliasByPath('/node/' . $entity->id(), $langcode);
    }

    try {
      if ($entity->hasLinkTemplate('canonical')) {
        return $entity->toUrl('canonical')->toString();
      }
    }
    catch (\Exception) {
      // Fall through to NULL for entities without usable canonical routes.
    }

    return NULL;
  }

  /**
   * Get the current request base URL when available.
   *
   * @return string|null
   *   Base URL or NULL.
   */
  protected function getBaseUrl(): ?string {
    try {
      $request = \Drupal::request();
      if ($request && $request->getHost()) {
        return $request->getSchemeAndHttpHost();
      }
    }
    catch (\Exception) {
      // CLI contexts may not have a meaningful request.
    }

    return NULL;
  }

  /**
   * Write a JSON file using the package JSON encoding contract.
   *
   * @param string $filePath
   *   Destination file path.
   * @param array $data
   *   Data to encode.
   *
   * @throws \Exception
   *   If encoding or writing fails.
   */
  protected function writeJsonFile(string $filePath, array $data): void {
    $directory = dirname($filePath);
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new \Exception("Failed to create directory: {$directory}");
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \Exception('JSON encoding failed: ' . json_last_error_msg());
    }

    if (file_put_contents($filePath, $json . "\n") === FALSE) {
      throw new \Exception("Failed to write file: {$filePath}");
    }
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
          $values = $this->enrichReferenceFieldValues($values, $definition, $element->language()->getId(), $sanitizeLocalUrls);
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
   * Add referenced media/file metadata to exported field payload values.
   *
   * The standalone migration runner needs downloadable source URLs for the
   * Drupal Migrate file/media stages. Pagedesigner fields often only store a
   * media target_id, so enrich these raw field values while keeping the source
   * item shape and target_id intact.
   *
   * @param array $values
   *   Raw field item values.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   Field definition.
   * @param string $langcode
   *   Current export language.
   * @param bool $sanitizeLocalUrls
   *   Whether local generated file URLs should be sanitized.
   *
   * @return array
   *   Enriched field item values.
   */
  protected function enrichReferenceFieldValues(array $values, FieldDefinitionInterface $definition, string $langcode, bool $sanitizeLocalUrls): array {
    $targetType = (string) $definition->getSetting('target_type');
    if (!in_array($targetType, ['media', 'file'], TRUE)) {
      return $values;
    }

    foreach ($values as $delta => $item) {
      if (!is_array($item) || empty($item['target_id'])) {
        continue;
      }

      $referencedEntity = $this->entityTypeManager->getStorage($targetType)->load($item['target_id']);
      if (!$referencedEntity instanceof ContentEntityInterface) {
        continue;
      }
      if ($referencedEntity->hasTranslation($langcode)) {
        $referencedEntity = $referencedEntity->getTranslation($langcode);
      }

      $metadata = $targetType === 'file'
        ? $this->buildFileReferenceMetadata($referencedEntity, NULL, $sanitizeLocalUrls)
        : $this->buildMediaReferenceMetadata($referencedEntity, $sanitizeLocalUrls);

      if (!$metadata) {
        continue;
      }

      $values[$delta]['referenced_entity'] = $metadata;
      if (!empty($metadata['files'][0]) && is_array($metadata['files'][0])) {
        $firstFile = $metadata['files'][0];
        $values[$delta]['file_target_id'] = $firstFile['target_id'] ?? NULL;
        $values[$delta]['filename'] = $firstFile['filename'] ?? NULL;
        $values[$delta]['uri'] = $firstFile['uri'] ?? NULL;
        $values[$delta]['url'] = $firstFile['url'] ?? NULL;
        $values[$delta]['sourceUrl'] = $firstFile['url'] ?? NULL;
        $values[$delta]['mime_type'] = $firstFile['mime_type'] ?? NULL;
      }
      elseif ($targetType === 'file') {
        $values[$delta]['filename'] = $metadata['filename'] ?? NULL;
        $values[$delta]['uri'] = $metadata['uri'] ?? NULL;
        $values[$delta]['url'] = $metadata['url'] ?? NULL;
        $values[$delta]['sourceUrl'] = $metadata['url'] ?? NULL;
        $values[$delta]['mime_type'] = $metadata['mime_type'] ?? NULL;
      }
    }

    return $values;
  }

  /**
   * Build metadata for a referenced media entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $media
   *   Referenced media entity.
   * @param bool $sanitizeLocalUrls
   *   Whether local generated file URLs should be sanitized.
   *
   * @return array
   *   Media metadata with nested file references.
   */
  protected function buildMediaReferenceMetadata(ContentEntityInterface $media, bool $sanitizeLocalUrls): array {
    $files = [];
    foreach ($media->getFieldDefinitions() as $fieldName => $definition) {
      if (!$media->hasField($fieldName) || $media->get($fieldName)->isEmpty()) {
        continue;
      }

      $fieldType = $definition->getType();
      $targetType = (string) $definition->getSetting('target_type');
      if (!in_array($fieldType, ['image', 'file'], TRUE) && $targetType !== 'file') {
        continue;
      }

      foreach ($media->get($fieldName) as $item) {
        if (empty($item->target_id) || empty($item->entity) || !$item->entity instanceof ContentEntityInterface) {
          continue;
        }

        $fileMetadata = $this->buildFileReferenceMetadata($item->entity, $fieldName, $sanitizeLocalUrls);
        if ($fileMetadata) {
          $files[] = $fileMetadata + [
            'field_name' => $fieldName,
            'alt' => isset($item->alt) ? (string) $item->alt : '',
            'title' => isset($item->title) ? (string) $item->title : '',
          ];
        }
      }
    }

    return [
      'entity_type' => $media->getEntityTypeId(),
      'id' => is_numeric($media->id()) ? (int) $media->id() : $media->id(),
      'uuid' => method_exists($media, 'uuid') ? $media->uuid() : NULL,
      'bundle' => $media->bundle(),
      'label' => $media->label(),
      'langcode' => $media->language()->getId(),
      'files' => $files,
    ];
  }

  /**
   * Build metadata for a referenced file entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $file
   *   Referenced file entity.
   * @param string|null $fieldName
   *   Parent media/source field name, if available.
   * @param bool $sanitizeLocalUrls
   *   Whether local generated file URLs should be sanitized.
   *
   * @return array
   *   File metadata.
   */
  protected function buildFileReferenceMetadata(ContentEntityInterface $file, ?string $fieldName, bool $sanitizeLocalUrls): array {
    $uri = method_exists($file, 'getFileUri') ? $file->getFileUri() : ($file->hasField('uri') ? $file->get('uri')->value : NULL);
    $url = $uri ? $this->fileUrlGenerator->generateAbsoluteString($uri) : NULL;
    if ($url && $sanitizeLocalUrls) {
      $url = $this->sanitizeLocalUrl($url);
    }

    return [
      'entity_type' => $file->getEntityTypeId(),
      'field_name' => $fieldName,
      'target_id' => is_numeric($file->id()) ? (int) $file->id() : $file->id(),
      'uuid' => method_exists($file, 'uuid') ? $file->uuid() : NULL,
      'filename' => method_exists($file, 'getFilename') ? $file->getFilename() : $file->label(),
      'uri' => $uri,
      'url' => $url,
      'mime_type' => method_exists($file, 'getMimeType') ? $file->getMimeType() : NULL,
      'filesize' => method_exists($file, 'getSize') ? (int) $file->getSize() : NULL,
    ];
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

<?php

namespace Drupal\pagedesigner_export\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\pagedesigner\Entity\Element;
use Drupal\user\EntityOwnerInterface;

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
    protected ?ModuleHandlerInterface $moduleHandler = NULL,
    protected ?MenuLinkManagerInterface $menuLinkManager = NULL,
  ) {}

  /**
   * Export contract schema version.
   */
  // 0.5.0 adds `field_definitions`: the declared schema of the fields an entity
  // exports, so target setup can create a counterpart field instead of guessing
  // its type from a sample value. Consumers check the MAJOR version only, so
  // older readers keep working.
  // 0.6.0 adds `users.json` (accounts + roles, so a migrated page can keep its
  // author) and `pagedesigner_child_count` per page (so a consumer can tell a
  // real composition from an empty one without fetching its tree).
  public const MIGRATION_SCHEMA_VERSION = '0.6.0';

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

    foreach ($manifest['pages'] as &$page) {
      $tree = $this->export((int) $page['pagedesigner_root_id'], $page['default_langcode'], $sanitizeLocalUrls);
      $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . $page['export_file'], $tree);
      // Stable per-page hash so re-exports can skip unchanged pages.
      $page['content_hash'] = sha1(json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
    unset($page);

    // Step-3 content (schema 0.3.0): taxonomy trees, menus, and entities
    // without pagedesigner roots — compositions reference them, so they
    // must exist on the target first.
    $taxonomies = $this->exportTaxonomies();
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'taxonomies.json', $taxonomies);
    $menus = $this->exportMenus();
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'menus.json', $menus);
    // Accounts and roles (schema 0.6.0): a page's owner travels as {uid, name},
    // which the target cannot resolve on its own — it matches by e-mail.
    $users = $this->exportUsers();
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'users.json', $users);

    $pd_entity_ids = [];
    foreach ($manifest['pages'] as $page) {
      $pd_entity_ids[(string) ($page['entity_type'] ?? 'node') . ':' . (string) ($page['entity_id'] ?? '')] = TRUE;
    }
    $entities = $this->discoverPlainEntities($options, $pd_entity_ids);
    foreach ($entities as &$entity_entry) {
      $payload = $this->exportPlainEntity($entity_entry, $sanitizeLocalUrls);
      $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . $entity_entry['export_file'], $payload);
      $entity_entry['content_hash'] = sha1(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
    unset($entity_entry);

    $manifest['content'] = [
      'taxonomies_file' => 'taxonomies.json',
      'vocabulary_count' => count($taxonomies['vocabularies'] ?? []),
      'menus_file' => 'menus.json',
      'menu_count' => count($menus['menus'] ?? []),
      'users_file' => 'users.json',
      'user_count' => count($users['users'] ?? []),
      'role_count' => count($users['roles'] ?? []),
      'entities' => $entities,
    ];
    $this->writeJsonFile($outputDirectory . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

    return [
      'manifest' => $manifest,
      'page_count' => count($manifest['pages']),
      'entity_count' => count($entities),
    ];
  }

  /**
   * Export every vocabulary with its full term tree and translations.
   */
  public function exportTaxonomies(): array {
    $vocabularies = [];
    if (!$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return ['vocabularies' => []];
    }
    $vocabulary_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    foreach ($vocabulary_storage->loadMultiple() as $vocabulary) {
      $terms = [];
      foreach ($term_storage->loadTree($vocabulary->id(), 0, NULL, TRUE) as $term) {
        /** @var \Drupal\taxonomy\TermInterface $term */
        $labels = [];
        foreach ($term->getTranslationLanguages() as $langcode => $language) {
          $labels[$langcode] = $term->getTranslation($langcode)->label();
        }
        $parents = $term_storage->loadParents($term->id());
        $parent = $parents ? (int) reset($parents)->id() : 0;
        $terms[] = [
          'tid' => (int) $term->id(),
          'uuid' => $term->uuid(),
          'name' => $term->label(),
          'labels' => $labels,
          'description' => (string) ($term->getDescription() ?? ''),
          'parent' => $parent,
          'weight' => (int) $term->getWeight(),
        ];
      }
      $vocabularies[] = [
        'vid' => $vocabulary->id(),
        'label' => $vocabulary->label(),
        'terms' => $terms,
      ];
    }

    return ['vocabularies' => $vocabularies];
  }

  /**
   * Export the accounts that own content, plus the site's roles.
   *
   * Per-node owners travel as `{uid, name}` on the page metadata: enough to
   * say WHO wrote a page, not enough to recreate the account on the target,
   * which matches an owner by e-mail. This is the list those uids resolve
   * against.
   *
   * The anonymous user is skipped (uid 0 owns nothing worth migrating) and the
   * implicit `authenticated` role is dropped from both lists — every account
   * has it, so it is never a mapping decision.
   */
  public function exportUsers(): array {
    if (!$this->entityTypeManager->hasDefinition('user')) {
      return ['users' => [], 'roles' => []];
    }

    $roles = [];
    if ($this->entityTypeManager->hasDefinition('user_role')) {
      foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
        if (in_array($role->id(), ['anonymous', 'authenticated'], TRUE)) {
          continue;
        }
        $roles[] = ['id' => $role->id(), 'label' => (string) $role->label()];
      }
    }

    $users = [];
    $user_storage = $this->entityTypeManager->getStorage('user');
    $uids = $user_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->sort('uid')
      ->execute();
    foreach ($user_storage->loadMultiple($uids) as $account) {
      /** @var \Drupal\user\UserInterface $account */
      $users[] = [
        'uid' => (int) $account->id(),
        'uuid' => $account->uuid(),
        'name' => $account->getAccountName(),
        'mail' => (string) ($account->getEmail() ?? ''),
        'status' => (bool) $account->isActive(),
        'roles' => array_values(array_diff($account->getRoles(), ['authenticated'])),
        'created' => (int) $account->getCreatedTime(),
      ];
    }

    return ['users' => $users, 'roles' => $roles];
  }

  /**
   * Export custom menus with their menu_link_content trees.
   */
  public function exportMenus(): array {
    $menus = [];
    if (!$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return ['menus' => []];
    }
    $menu_storage = $this->entityTypeManager->getStorage('menu');
    $link_storage = $this->entityTypeManager->getStorage('menu_link_content');

    foreach ($menu_storage->loadMultiple() as $menu) {
      $link_ids = $link_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('menu_name', $menu->id())
        ->sort('weight', 'ASC')
        ->execute();
      if (!$link_ids) {
        continue;
      }
      $links = [];
      foreach ($link_storage->loadMultiple($link_ids) as $link) {
        /** @var \Drupal\menu_link_content\MenuLinkContentInterface $link */
        $titles = [];
        foreach ($link->getTranslationLanguages() as $langcode => $language) {
          $titles[$langcode] = $link->getTranslation($langcode)->getTitle();
        }
        $links[] = [
          'uuid' => $link->uuid(),
          'title' => $link->getTitle(),
          'titles' => $titles,
          'uri' => $link->link->uri ?? '',
          'parent' => (string) $link->getParentId(),
          'weight' => (int) $link->getWeight(),
          'enabled' => $link->isEnabled(),
          'expanded' => $link->isExpanded(),
          'langcode' => $link->language()->getId(),
        ];
      }
      $menus[] = [
        'id' => $menu->id(),
        'label' => $menu->label(),
        'links' => $links,
      ];
    }

    return ['menus' => $menus];
  }

  /**
   * Discover content entities without pagedesigner roots.
   *
   * @param array $options
   *   Export options (entity_type, bundle filters apply here too).
   * @param array $pd_entity_ids
   *   Map of "entity_type:id" already exported as pagedesigner pages.
   *
   * @return array
   *   Manifest entity entries (without content_hash yet).
   */
  public function discoverPlainEntities(array $options, array $pd_entity_ids): array {
    $entityTypeId = (string) ($options['entity_type'] ?? 'node');
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
    if (!$entityType || !$entityType->entityClassImplements(ContentEntityInterface::class)) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    $idKey = $entityType->getKey('id') ?: 'id';
    $includeUnpublished = (bool) ($options['include_unpublished'] ?? FALSE);
    $limit = isset($options['entity_limit']) && $options['entity_limit'] !== NULL && $options['entity_limit'] !== ''
      ? (int) $options['entity_limit']
      : NULL;

    $query = $storage->getQuery()->accessCheck(FALSE)->sort($idKey, 'ASC');
    $ids = $query->execute();
    $entries = [];
    $pd_fields_by_bundle = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $key = $entityTypeId . ':' . $entity->id();
      if (isset($pd_entity_ids[$key])) {
        continue;
      }
      // A pagedesigner-bearing entity is a composition page even when the
      // page limit kept it out of this run's pages — never a plain entity.
      $bundle = $entity->bundle();
      // Pagedesigner-ecosystem bundles are editor infrastructure, never
      // migratable content (see discoverMigrationPages).
      if (str_starts_with($bundle, 'pagedesigner')) {
        continue;
      }
      if (!array_key_exists($bundle, $pd_fields_by_bundle)) {
        $pd_fields_by_bundle[$bundle] = $this->getPagedesignerFields($entityTypeId, $bundle, NULL);
      }
      if ($pd_fields_by_bundle[$bundle]) {
        $all_langcodes = $this->getExportableLangcodes($entity, TRUE);
        if ($this->getPagedesignerRootsByField($entity, $pd_fields_by_bundle[$bundle], $all_langcodes)) {
          continue;
        }
      }
      $langcodes = $this->getExportableLangcodes($entity, $includeUnpublished);
      if (!$langcodes) {
        continue;
      }
      $titles = [];
      $paths = [];
      $statuses = [];
      foreach ($langcodes as $langcode) {
        $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
        $titles[$langcode] = $translation->label();
        $paths[$langcode] = $this->getEntityPath($translation, $langcode);
        $statuses[$langcode] = $translation instanceof EntityPublishedInterface ? $translation->isPublished() : TRUE;
      }
      $entry = [
        'id' => $key,
        'entity_type' => $entityTypeId,
        'bundle' => $entity->bundle(),
        'entity_id' => is_numeric($entity->id()) ? (int) $entity->id() : $entity->id(),
        'uuid' => method_exists($entity, 'uuid') ? $entity->uuid() : NULL,
        'default_langcode' => $entity->language()->getId(),
        'langcodes' => $langcodes,
        'titles' => $titles,
        'paths' => $paths,
        'statuses' => $statuses,
        'export_file' => 'entities/' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', $entityTypeId . '-' . $entity->id()) . '.json',
      ];
      $entry += $this->buildEntityMetadata($entity, $langcodes);
      $entries[] = $entry;
      if ($limit !== NULL && count($entries) >= $limit) {
        break;
      }
    }

    return $entries;
  }

  /**
   * Base and bookkeeping fields no migration ever maps.
   *
   * Identity, workflow flags and revision metadata: the manifest already
   * carries what matters (title, status, dates, author, path), and the rest is
   * storage plumbing.
   */
  protected const SKIP_FIELDS = [
    'nid', 'vid', 'id', 'uuid', 'type', 'uid', 'title', 'status', 'created',
    'changed', 'promote', 'sticky', 'default_langcode', 'langcode', 'path',
    'menu_link', 'comment', 'revision_timestamp', 'revision_uid',
    'revision_log', 'revision_default', 'revision_translation_affected',
    'content_translation_source', 'content_translation_outdated',
    'metatag',
  ];

  /**
   * Field types that are plugin plumbing, never migratable content.
   *
   * SEO modules attach these under project-specific names
   * (`field_meta_tags`, `field_yoast_seo`), so the type is the only stable
   * way to recognise them.
   */
  protected const SKIP_FIELD_TYPES = ['metatag', 'yoast_seo'];

  /**
   * One translation's own field values, enriched and optionally sanitized.
   *
   * Shared by plain entities and pagedesigner pages: a composition page's node
   * carries content of its own (lead text, teaser image, documents) that the
   * element tree knows nothing about, and the migration cannot map what was
   * never exported.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $translation
   *   The entity in the language being exported.
   * @param string $langcode
   *   That language.
   * @param bool $sanitizeLocalUrls
   *   Whether to rewrite local URLs.
   * @param string[] $additionalSkipFields
   *   Extra field names to omit — a page's pagedesigner field, whose tree is
   *   exported separately and must not be duplicated here.
   *
   * @return array
   *   Field name => field values.
   */
  /**
   * Declared schema of the fields an entity exports.
   *
   * The values alone cannot be migrated into a target field: a consumer that
   * only sees `[{"value": "..."}]` has to guess the type, and guesses cannot
   * create a field. This exports what Drupal actually declares, so target
   * setup can create the counterpart without asking a human to retype it.
   *
   * Language-independent, so it is exported once per entity rather than per
   * translation.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity in its default language.
   * @param string[] $additionalSkipFields
   *   Extra field names to omit, matching collectEntityFields().
   *
   * @return array
   *   Field name => declared schema.
   */
  protected function collectFieldDefinitions(ContentEntityInterface $entity, array $additionalSkipFields = []): array {
    $skipFields = array_merge(self::SKIP_FIELDS, $additionalSkipFields);
    $definitions = [];
    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if (in_array($fieldName, $skipFields, TRUE) || str_starts_with($fieldName, 'revision_')) {
        continue;
      }
      if (in_array($definition->getType(), self::SKIP_FIELD_TYPES, TRUE)) {
        continue;
      }
      if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
        continue;
      }
      $settings = $definition->getSettings();
      $handlerSettings = $settings['handler_settings'] ?? [];
      $info = [
        'type' => $definition->getType(),
        // -1 means unlimited; the observed value count cannot reveal this.
        'cardinality' => (int) $definition->getFieldStorageDefinition()->getCardinality(),
        'label' => (string) $definition->getLabel(),
        'required' => (bool) $definition->isRequired(),
        'translatable' => (bool) $definition->isTranslatable(),
      ];
      if (!empty($settings['target_type'])) {
        $info['target_type'] = (string) $settings['target_type'];
      }
      if (!empty($handlerSettings['target_bundles'])) {
        $info['target_bundles'] = array_values(array_map('strval', $handlerSettings['target_bundles']));
      }
      if (!empty($settings['max_length'])) {
        $info['max_length'] = (int) $settings['max_length'];
      }
      $definitions[$fieldName] = $info;
    }
    return $definitions;
  }

  protected function collectEntityFields(ContentEntityInterface $translation, string $langcode, bool $sanitizeLocalUrls, array $additionalSkipFields = []): array {
    $skipFields = array_merge(self::SKIP_FIELDS, $additionalSkipFields);
    $fields = [];
    foreach ($translation->getFieldDefinitions() as $fieldName => $definition) {
      if (in_array($fieldName, $skipFields, TRUE) || str_starts_with($fieldName, 'revision_')) {
        continue;
      }
      if (in_array($definition->getType(), self::SKIP_FIELD_TYPES, TRUE)) {
        continue;
      }
      if (!$translation->hasField($fieldName) || $translation->get($fieldName)->isEmpty()) {
        continue;
      }
      $values = $translation->get($fieldName)->getValue();
      $values = $this->enrichReferenceFieldValues($values, $definition, $langcode, $sanitizeLocalUrls);
      if ($sanitizeLocalUrls) {
        $values = $this->sanitizeFieldValues($values);
      }
      $fields[$fieldName] = $values;
    }
    return $fields;
  }

  /**
   * Export a plain (non-pagedesigner) entity's fields per translation.
   */
  public function exportPlainEntity(array $entry, bool $sanitizeLocalUrls): array {
    $storage = $this->entityTypeManager->getStorage((string) $entry['entity_type']);
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $storage->load($entry['entity_id']);

    $translations = [];
    foreach ($entry['langcodes'] as $langcode) {
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      $translations[$langcode] = [
        'title' => $translation->label(),
        'status' => $translation instanceof EntityPublishedInterface ? $translation->isPublished() : TRUE,
        'fields' => $this->collectEntityFields($translation, $langcode, $sanitizeLocalUrls),
      ];
    }

    return [
      'schema_version' => self::MIGRATION_SCHEMA_VERSION,
      'id' => $entry['id'],
      'entity_type' => $entry['entity_type'],
      'bundle' => $entry['bundle'],
      'entity_id' => $entry['entity_id'],
      'uuid' => $entry['uuid'],
      'default_langcode' => $entry['default_langcode'],
      // Declared schema, once per entity: the values alone cannot tell a
      // consumer what field to create on the target.
      'field_definitions' => $this->collectFieldDefinitions($entity),
      'translations' => $translations,
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
    $sanitizeLocalUrls = (bool) ($options['sanitize_local_urls'] ?? TRUE);
    $limit = isset($options['limit']) && $options['limit'] !== NULL && $options['limit'] !== '' ? (int) $options['limit'] : NULL;
    $bundles = $this->getBundles($entityTypeId, $bundleFilter);
    $pages = [];

    foreach ($bundles as $bundle) {
      // Pagedesigner-ecosystem bundles (pagedesigner_part reusable snippets
      // etc.) are editor infrastructure, not site content — their markup is
      // already inlined where pages use them. Never migrate them.
      if (str_starts_with($bundle, 'pagedesigner')) {
        continue;
      }
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
            $pages[] = $this->buildPageManifestEntry($entity, $fieldName, (string) $rootId, $langcodes, $rootCount > 1, $sanitizeLocalUrls);
            if ($limit !== NULL && count($pages) >= $limit) {
              break 3;
            }
          }
        }
      }
    }

    $this->stampCompositionSize($pages);

    return $pages;
  }

  /**
   * Stamp each page with how many elements its composition root holds.
   *
   * A bundle can carry a PageDesigner field and still have nothing in it: on
   * the Trachtverein fleet every bundle has `field_pagedesigner_content`, and
   * 879 of 945 roots are childless. The consumer fetches one tree per page, so
   * without this it spends 879 round-trips discovering that those pages have
   * no composition — and the node's own fields, which is all they actually
   * carry, are already on this manifest entry.
   *
   * Deliberately the DIRECT child count, not a recursive element count: it is
   * one field read per root rather than a tree walk, and zero children is the
   * only claim a consumer may safely act on. Anything above zero is fetched as
   * before.
   */
  protected function stampCompositionSize(array &$pages): void {
    $rootIds = [];
    foreach ($pages as $page) {
      $rootId = (int) ($page['pagedesigner_root_id'] ?? 0);
      if ($rootId) {
        $rootIds[$rootId] = TRUE;
      }
    }
    if (!$rootIds) {
      return;
    }

    // One load for the whole site: per-page loads would trade the consumer's
    // round-trips for our own.
    $roots = $this->entityTypeManager->getStorage('pagedesigner_element')
      ->loadMultiple(array_keys($rootIds));

    foreach ($pages as &$page) {
      $rootId = (int) ($page['pagedesigner_root_id'] ?? 0);
      $root = $roots[$rootId] ?? NULL;
      if ($root === NULL) {
        continue;
      }
      $page['pagedesigner_child_count'] = $root->hasField('children')
        ? $root->get('children')->count()
        : 0;
    }
    unset($page);
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
      if ($definition->getSetting('target_type') === 'pagedesigner_element' && in_array($definition->getType(), ['entity_reference', 'entity_reference_revisions', 'pagedesigner_item'], TRUE)) {
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
  protected function buildPageManifestEntry(ContentEntityInterface $entity, string $fieldName, string $rootId, array $langcodes, bool $forceUniqueId, bool $sanitizeLocalUrls = TRUE): array {
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

    $entry += $this->buildEntityMetadata($entity, $langcodes, $sanitizeLocalUrls, [$fieldName]);

    return $entry;
  }

  /**
   * Build schema 0.2.0 entity metadata for one manifest page entry.
   *
   * Everything here is additive and null-safe so 0.1.x consumers keep
   * working: dates and authorship per translation, taxonomy assignments,
   * menu placement, and redirects pointing at the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   * @param string[] $langcodes
   *   Exported language codes.
   *
   * @return array
   *   Metadata keys to merge into the manifest entry.
   */
  protected function buildEntityMetadata(ContentEntityInterface $entity, array $langcodes, bool $sanitizeLocalUrls = TRUE, array $skipFields = []): array {
    $created = [];
    $changed = [];
    $authors = [];
    $fields = [];
    foreach ($langcodes as $langcode) {
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      $translationFields = $this->collectEntityFields($translation, $langcode, $sanitizeLocalUrls, $skipFields);
      if ($translationFields) {
        $fields[$langcode] = $translationFields;
      }
      if (method_exists($translation, 'getCreatedTime')) {
        $created[$langcode] = (int) $translation->getCreatedTime();
      }
      if ($translation instanceof EntityChangedInterface) {
        $changed[$langcode] = (int) $translation->getChangedTime();
      }
      if ($translation instanceof EntityOwnerInterface) {
        $owner = $translation->getOwner();
        $authors[$langcode] = [
          'uid' => (int) $translation->getOwnerId(),
          'name' => $owner ? $owner->getDisplayName() : NULL,
        ];
      }
    }

    $metadata = [];
    if ($created) {
      $metadata['created'] = $created;
    }
    if ($changed) {
      $metadata['changed'] = $changed;
    }
    if ($authors) {
      $metadata['authors'] = $authors;
    }
    // The node's own fields, per language. A composition page carries content
    // the element tree knows nothing about — lead text, teaser image,
    // documents — and the migration cannot map what was never exported.
    if ($fields) {
      $metadata['fields'] = $fields;
      $metadata['field_definitions'] = $this->collectFieldDefinitions($entity, $skipFields);
    }

    $taxonomies = $this->buildTaxonomyAssignments($entity);
    if ($taxonomies) {
      $metadata['taxonomies'] = $taxonomies;
    }
    $menuLinks = $this->buildMenuPlacement($entity);
    if ($menuLinks) {
      $metadata['menu_links'] = $menuLinks;
    }
    $redirects = $this->buildRedirects($entity);
    if ($redirects) {
      $metadata['redirects'] = $redirects;
    }

    return $metadata;
  }

  /**
   * Collect taxonomy-term assignments from the entity's reference fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity (default translation; term identity is shared).
   *
   * @return array
   *   One entry per populated taxonomy reference field.
   */
  protected function buildTaxonomyAssignments(ContentEntityInterface $entity): array {
    $assignments = [];
    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if ($definition->getSetting('target_type') !== 'taxonomy_term') {
        continue;
      }
      if (!$entity->hasField($fieldName) || $entity->get($fieldName)->isEmpty()) {
        continue;
      }
      $terms = [];
      foreach ($entity->get($fieldName) as $item) {
        $term = $item->entity;
        if (!$term instanceof ContentEntityInterface) {
          continue;
        }
        $labels = [];
        foreach ($term->getTranslationLanguages() as $termLangcode => $language) {
          $labels[$termLangcode] = $term->getTranslation($termLangcode)->label();
        }
        $terms[] = [
          'tid' => is_numeric($term->id()) ? (int) $term->id() : $term->id(),
          'uuid' => method_exists($term, 'uuid') ? $term->uuid() : NULL,
          'name' => $term->label(),
          'labels' => $labels,
          'vocabulary' => $term->bundle(),
        ];
      }
      if ($terms) {
        $assignments[] = [
          'field' => $fieldName,
          'terms' => $terms,
        ];
      }
    }

    return $assignments;
  }

  /**
   * Collect menu placement for a node entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   *
   * @return array
   *   Menu link entries ({menu_name, title, parent, weight, enabled}).
   */
  protected function buildMenuPlacement(ContentEntityInterface $entity): array {
    if (!$this->menuLinkManager || $entity->getEntityTypeId() !== 'node') {
      return [];
    }

    $links = [];
    try {
      $instances = $this->menuLinkManager->loadLinksByRoute('entity.node.canonical', ['node' => $entity->id()]);
    }
    catch (\Exception) {
      return [];
    }
    foreach ($instances as $instance) {
      $links[] = [
        'menu_name' => $instance->getMenuName(),
        'title' => (string) $instance->getTitle(),
        'parent' => $instance->getParent() ?: NULL,
        'weight' => (int) $instance->getWeight(),
        'enabled' => (bool) $instance->isEnabled(),
      ];
    }

    return $links;
  }

  /**
   * Collect redirects that point at the entity's canonical path.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Source entity.
   *
   * @return array
   *   Redirect entries ({source, langcode, status_code}).
   */
  protected function buildRedirects(ContentEntityInterface $entity): array {
    if (!$this->moduleHandler || !$this->moduleHandler->moduleExists('redirect')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('redirect');

    $redirects = [];
    try {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('redirect_redirect__uri', [
          'internal:/' . $entity->getEntityTypeId() . '/' . $entity->id(),
          'entity:' . $entity->getEntityTypeId() . '/' . $entity->id(),
        ], 'IN')
        ->execute();
    }
    catch (\Exception) {
      return [];
    }
    foreach ($storage->loadMultiple($ids) as $redirect) {
      $source = method_exists($redirect, 'getSourcePathWithQuery') ? $redirect->getSourcePathWithQuery() : NULL;
      $redirects[] = [
        'source' => $source,
        'langcode' => $redirect->language()->getId(),
        'status_code' => method_exists($redirect, 'getStatusCode') ? (int) $redirect->getStatusCode() : NULL,
      ];
    }

    return $redirects;
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
      $payloadFile = $this->pickMediaPayloadFile($metadata);
      if ($payloadFile) {
        $firstFile = $payloadFile;
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
      // `files` is field-definition ordered, so `thumbnail` — Drupal's
      // generated preview — can precede the media's real payload. Naming the
      // source field lets a consumer pick the file the media actually is.
      'source_field' => $this->getMediaSourceFieldName($media),
      'files' => $files,
    ];
  }

  /**
   * The file a media entity actually carries, not its generated thumbnail.
   *
   * @param array $metadata
   *   Media reference metadata from buildMediaReferenceMetadata().
   *
   * @return array|null
   *   The payload file entry, or NULL when the media carries no file.
   */
  protected function pickMediaPayloadFile(array $metadata): ?array {
    $files = array_values(array_filter($metadata['files'] ?? [], 'is_array'));
    if (!$files) {
      return NULL;
    }
    $sourceField = (string) ($metadata['source_field'] ?? '');
    if ($sourceField !== '') {
      foreach ($files as $file) {
        if (($file['field_name'] ?? '') === $sourceField) {
          return $file;
        }
      }
    }
    foreach ($files as $file) {
      if (($file['field_name'] ?? '') !== 'thumbnail') {
        return $file;
      }
    }
    return $files[0];
  }

  /**
   * The field a media type stores its actual file in.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $media
   *   The media entity.
   *
   * @return string
   *   The source field name, or '' when it cannot be resolved.
   */
  protected function getMediaSourceFieldName(ContentEntityInterface $media): string {
    if (!method_exists($media, 'getSource')) {
      return '';
    }
    try {
      $configuration = $media->getSource()->getConfiguration();
      return (string) ($configuration['source_field'] ?? '');
    }
    catch (\Throwable $exception) {
      return '';
    }
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

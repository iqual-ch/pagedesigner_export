<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\pagedesigner_export\Service\Exporter;
use Psr\Log\LoggerInterface;

/**
 * Pagedesigner MCP operations — the source-side export business logic.
 *
 * Transport-agnostic: the mcp_server #[Tool] plugins
 * (src/Plugin/mcp_server/Tool/) are thin adapters over this service.
 * execute() returns RAW result arrays — the transport applies its own
 * envelope.
 */
final class PagedesignerMcpOperations {

  public function __construct(
    protected Exporter $exporter,
    protected LanguageManagerInterface $languageManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Dispatch one tool call; never lets a throwable reach the transport.
   */
  public function execute(string $toolId, mixed $arguments): array {
    try {
      $arguments = is_array($arguments) ? $arguments : [];
      switch ($toolId) {
        case 'get_migration_manifest':
          return $this->getMigrationManifest($arguments);

        case 'get_page_tree':
          return $this->getPageTree(
            (int) ($arguments['pagedesigner_root_id'] ?? 0),
            (string) ($arguments['langcode'] ?? ''),
          );

        case 'get_taxonomies':
          return $this->exporter->exportTaxonomies() + ['status' => 'ok'];

        case 'get_menus':
          return $this->exporter->exportMenus() + ['status' => 'ok'];

        case 'get_users':
          return $this->exporter->exportUsers(
            (int) ($arguments['after_uid'] ?? 0),
            $this->pageSize($arguments, 'limit'),
          ) + ['status' => 'ok'];

        case 'get_theme_settings':
          return $this->exporter->exportTheme() + ['status' => 'ok'];

        case 'get_webforms':
          return $this->exporter->exportWebforms(
            (bool) ($arguments['include_submissions'] ?? TRUE),
            isset($arguments['webform_id']) && $arguments['webform_id'] !== '' ? (string) $arguments['webform_id'] : NULL,
            (int) ($arguments['after_sid'] ?? 0),
            $this->pageSize($arguments, 'limit'),
          ) + ['status' => 'ok'];

        case 'get_entities':
          return $this->getEntities($arguments);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('pagedesigner_mcp tool error: @msg', ['@msg' => $e->getMessage()]);
      return [
        'status' => 'error',
        'error' => $e->getMessage(),
        'tool_id' => $toolId,
      ];
    }
    throw new \InvalidArgumentException('pagedesigner_mcp: unknown tool id ' . $toolId);
  }

  /**
   * Manifest plus the step-3 content summary the package variant carries.
   */
  protected function getMigrationManifest(array $arguments): array {
    $options = $this->exportOptions($arguments);
    $batch_size = $this->pageSize($arguments, 'batch_size');
    if ($batch_size !== NULL) {
      return $this->getMigrationManifestBatch($options, (int) ($arguments['after_id'] ?? 0), $batch_size);
    }
    $manifest = $this->exporter->buildMigrationManifest($options);

    $taxonomies = $this->exporter->exportTaxonomies();
    $menus = $this->exporter->exportMenus();
    $pd_entity_ids = [];
    foreach ($manifest['pages'] as $page) {
      $pd_entity_ids[(string) ($page['entity_type'] ?? 'node') . ':' . (string) ($page['entity_id'] ?? '')] = TRUE;
    }
    $entities = $this->exporter->discoverPlainEntities($options, $pd_entity_ids);
    $users = $this->exporter->exportUsers();
    $webforms = $this->exporter->exportWebforms(FALSE);
    $manifest['content'] = [
      'vocabulary_count' => count($taxonomies['vocabularies'] ?? []),
      'menu_count' => count($menus['menus'] ?? []),
      'user_count' => count($users['users'] ?? []),
      'role_count' => count($users['roles'] ?? []),
      'webform_count' => count($webforms['webforms'] ?? []),
      'entities' => $entities,
    ];
    return $manifest + ['status' => 'ok'];
  }

  /**
   * One slice of the manifest (schema 0.16.0): pages and plain entities.
   *
   * The `source` block and the theme summary come with every slice — both
   * are cheap — so a consumer can take them from whichever slice it likes.
   * The step-3 counts of the unpaged manifest are left out: they cost a full
   * export of users and webforms, which is what paging exists to avoid.
   */
  protected function getMigrationManifestBatch(array $options, int $after_id, int $batch_size): array {
    $manifest = $this->exporter->buildMigrationManifest(['limit' => 0] + $options);
    $batch = $this->exporter->discoverEntityBatch($options, $after_id, $batch_size);
    $manifest['pages'] = $batch['pages'];
    $manifest['content'] = ['entities' => $batch['entities']];
    $manifest['paging'] = $batch['paging'];
    return $manifest + ['status' => 'ok'];
  }

  /**
   * One element tree with the stable content hash the package variant has.
   */
  protected function getPageTree(int $root_id, string $langcode): array {
    if ($root_id <= 0) {
      return ['status' => 'error', 'error' => 'pagedesigner_root_id is required.'];
    }
    if ($langcode === '') {
      $langcode = $this->languageManager->getDefaultLanguage()->getId();
    }
    $tree = $this->exporter->export($root_id, $langcode);
    return [
      'status' => 'ok',
      'pagedesigner_root_id' => $root_id,
      'langcode' => $langcode,
      'content_hash' => sha1(json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
      'tree' => $tree,
    ];
  }

  /**
   * Plain (non-Pagedesigner) entity payloads, optionally filtered by key.
   */
  protected function getEntities(array $arguments): array {
    $options = $this->exportOptions($arguments);
    $requested = array_values(array_filter(array_map('strval', (array) ($arguments['entity_keys'] ?? []))));
    if ($requested) {
      // The caller knows which entities it wants (from a paged manifest):
      // load those, never the whole site.
      $entries = $this->exporter->plainEntityEntries($requested, $options);
    }
    else {
      $manifest = $this->exporter->buildMigrationManifest($options);
      $pd_entity_ids = [];
      foreach ($manifest['pages'] as $page) {
        $pd_entity_ids[(string) ($page['entity_type'] ?? 'node') . ':' . (string) ($page['entity_id'] ?? '')] = TRUE;
      }
      $entries = $this->exporter->discoverPlainEntities($options, $pd_entity_ids);
    }

    $entities = [];
    foreach ($entries as $entry) {
      $payload = $this->exporter->exportPlainEntity($entry, TRUE);
      $entities[] = [
        'entry' => $entry + [
          'content_hash' => sha1(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ],
        'payload' => $payload,
      ];
    }
    return ['status' => 'ok', 'entity_count' => count($entities), 'entities' => $entities];
  }

  /**
   * A positive page size from the tool arguments, or NULL for "unpaged".
   */
  protected function pageSize(array $arguments, string $key): ?int {
    $value = $arguments[$key] ?? NULL;
    return is_numeric($value) && (int) $value > 0 ? (int) $value : NULL;
  }

  /**
   * Shared exporter options from tool arguments.
   */
  protected function exportOptions(array $arguments): array {
    $options = [];
    if (!empty($arguments['bundle'])) {
      $options['bundle'] = (string) $arguments['bundle'];
    }
    if (!empty($arguments['limit'])) {
      $options['limit'] = (int) $arguments['limit'];
    }
    if (!empty($arguments['include_unpublished'])) {
      $options['include_unpublished'] = TRUE;
    }
    return $options;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\pagedesigner_export\Service\Exporter;
use Psr\Log\LoggerInterface;

/**
 * Pagedesigner MCP operations — the source-side export business logic.
 *
 * Transport-agnostic: the mcp_server #[Tool] plugins (src/Plugin/Tool/) and
 * the legacy drupal/mcp plugin (src/Plugin/Mcp/, transition only) are thin
 * adapters over this service. execute() returns RAW result arrays — each
 * transport applies its own envelope.
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
    $manifest = $this->exporter->buildMigrationManifest($options);

    $taxonomies = $this->exporter->exportTaxonomies();
    $menus = $this->exporter->exportMenus();
    $pd_entity_ids = [];
    foreach ($manifest['pages'] as $page) {
      $pd_entity_ids[(string) ($page['entity_type'] ?? 'node') . ':' . (string) ($page['entity_id'] ?? '')] = TRUE;
    }
    $entities = $this->exporter->discoverPlainEntities($options, $pd_entity_ids);
    $manifest['content'] = [
      'vocabulary_count' => count($taxonomies['vocabularies'] ?? []),
      'menu_count' => count($menus['menus'] ?? []),
      'entities' => $entities,
    ];
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
    $manifest = $this->exporter->buildMigrationManifest($options);
    $pd_entity_ids = [];
    foreach ($manifest['pages'] as $page) {
      $pd_entity_ids[(string) ($page['entity_type'] ?? 'node') . ':' . (string) ($page['entity_id'] ?? '')] = TRUE;
    }
    $entries = $this->exporter->discoverPlainEntities($options, $pd_entity_ids);

    $requested = array_filter(array_map('strval', (array) ($arguments['entity_keys'] ?? [])));
    if ($requested) {
      $wanted = array_flip($requested);
      $entries = array_values(array_filter(
        $entries,
        static fn (array $entry): bool => isset(
          $wanted[(string) ($entry['entity_type'] ?? 'node') . ':' . (string) ($entry['entity_id'] ?? '')]
        ),
      ));
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

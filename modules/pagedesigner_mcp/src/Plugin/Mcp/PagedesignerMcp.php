<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Plugin\Mcp;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Drupal\pagedesigner_export\Service\Exporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pagedesigner MCP plugin — the source-side sibling of icms_mcp.
 *
 * Read-only tools exposing the pagedesigner_export Exporter over MCP, with
 * full parity to the `drush pd:migration-export` file package (schema
 * 0.3.0): the agent's `ingest` consumes either transport identically.
 *
 * Tools exposed:
 *   - get_migration_manifest: per-page bundle/uuid/langcodes/titles/paths/
 *     publication state + step-3 content summary
 *   - get_page_tree: one Pagedesigner element tree (all translations, media
 *     enrichment) + content hash for incremental sync
 *   - get_taxonomies: full term trees with hierarchy + per-language labels
 *   - get_menus: menu_link_content trees
 *   - get_entities: content entities WITHOUT Pagedesigner roots
 *
 * Security model: the export contains everything, including unpublished and
 * intranet content — the module is enabled per site only during its
 * migration window (see the service-account lifecycle in the .install).
 */
#[Mcp(
  id: 'pagedesigner-mcp',
  name: new TranslatableMarkup('Pagedesigner MCP'),
  description: new TranslatableMarkup('Read-only Pagedesigner migration export tools (manifest, page trees, taxonomies, menus, plain entities) for the iqual ai-platform content-migrator agent.'),
)]
class PagedesignerMcp extends McpPluginBase implements ContainerFactoryPluginInterface {

  protected Exporter $exporter;
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    /** @var self $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->exporter = $container->get('pagedesigner_export.exporter');
    $instance->logger = $container->get('logger.factory')->get('pagedesigner_mcp');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getTools(): array {
    $manifest_options = [
      'bundle' => [
        'type' => 'string',
        'description' => 'Optional node bundle filter (e.g. "page").',
      ],
      'limit' => [
        'type' => 'integer',
        'description' => 'Optional cap on the number of Pagedesigner pages.',
      ],
      'include_unpublished' => [
        'type' => 'boolean',
        'description' => 'Include unpublished page translations.',
        'default' => FALSE,
      ],
    ];
    return [
      new Tool(
        name: 'get_migration_manifest',
        description: 'Return the migration manifest: schema/source info plus one entry per Pagedesigner page (bundle, uuid, langcodes, titles, paths, publication state, created/changed, author) and a step-3 content summary (taxonomy/menu counts, plain entities). Same shape as the manifest.json of a drush pd:migration-export package, without per-page content hashes (fetch trees for those).',
        inputSchema: [
          'type' => 'object',
          'properties' => $manifest_options,
          'required' => [],
        ],
      ),
      new Tool(
        name: 'get_page_tree',
        description: 'Export one Pagedesigner element tree (rows, cells, typed elements with field values, ALL translations, media enrichment with downloadable URLs) plus its content_hash for incremental sync. Same shape as a pages/*.json file of the drush package.',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'pagedesigner_root_id' => [
              'type' => 'integer',
              'description' => 'The pagedesigner_root_id from a manifest page entry.',
            ],
            'langcode' => [
              'type' => 'string',
              'description' => 'Base language of the tree; defaults to the page default_langcode from the manifest.',
            ],
          ],
          'required' => ['pagedesigner_root_id'],
        ],
      ),
      new Tool(
        name: 'get_taxonomies',
        description: 'Export every vocabulary with its full term tree: hierarchy, weights, per-language labels, uuids. Same shape as taxonomies.json of the drush package. Terms import BEFORE nodes on the target.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [],
          'required' => [],
        ],
      ),
      new Tool(
        name: 'get_menus',
        description: 'Export custom menus with their menu_link_content trees (uuids, per-language titles, uris, hierarchy). Same shape as menus.json of the drush package. Menus import AFTER nodes on the target.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) [],
          'required' => [],
        ],
      ),
      new Tool(
        name: 'get_entities',
        description: 'Export content entities WITHOUT Pagedesigner roots (news, FAQs, …): per-translation fields with media enrichment, one payload per entity. Same shape as the entities/*.json files of the drush package. Optionally filter by entity keys ("node:45").',
        inputSchema: [
          'type' => 'object',
          'properties' => [
            'entity_keys' => [
              'type' => 'array',
              'items' => ['type' => 'string'],
              'description' => 'Optional list of "entity_type:id" keys to export; omit for all.',
            ],
            'bundle' => [
              'type' => 'string',
              'description' => 'Optional bundle filter.',
            ],
            'include_unpublished' => [
              'type' => 'boolean',
              'description' => 'Include unpublished entities.',
              'default' => FALSE,
            ],
          ],
          'required' => [],
        ],
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function executeTool(string $toolId, mixed $arguments): array {
    try {
      if ($toolId === 'get_migration_manifest' || $toolId === md5('get_migration_manifest')) {
        return $this->jsonResponse($this->doGetMigrationManifest(is_array($arguments) ? $arguments : []));
      }
      if ($toolId === 'get_page_tree' || $toolId === md5('get_page_tree')) {
        return $this->jsonResponse($this->doGetPageTree(
          (int) ($arguments['pagedesigner_root_id'] ?? 0),
          (string) ($arguments['langcode'] ?? ''),
        ));
      }
      if ($toolId === 'get_taxonomies' || $toolId === md5('get_taxonomies')) {
        return $this->jsonResponse($this->exporter->exportTaxonomies() + ['status' => 'ok']);
      }
      if ($toolId === 'get_menus' || $toolId === md5('get_menus')) {
        return $this->jsonResponse($this->exporter->exportMenus() + ['status' => 'ok']);
      }
      if ($toolId === 'get_entities' || $toolId === md5('get_entities')) {
        return $this->jsonResponse($this->doGetEntities(is_array($arguments) ? $arguments : []));
      }
    }
    catch (\Throwable $e) {
      // Never let an uncaught throwable reach the MCP transport — it would
      // surface to the agent as a connection error rather than a structured
      // tool result the LLM can reason about.
      $this->logger->error('pagedesigner_mcp tool error: @msg', ['@msg' => $e->getMessage()]);
      return $this->jsonResponse([
        'status' => 'error',
        'error' => $e->getMessage(),
        'tool_id' => $toolId,
      ]);
    }
    throw new \InvalidArgumentException('pagedesigner_mcp: unknown tool id ' . $toolId);
  }

  /**
   * {@inheritdoc}
   */
  public function hasAccess(): AccessResult {
    return AccessResult::allowedIfHasPermission($this->currentUser, 'use pagedesigner_mcp tools');
  }

  // ---- Tool: get_migration_manifest ---------------------------------------

  /**
   * Manifest plus the step-3 content summary the package variant carries.
   */
  protected function doGetMigrationManifest(array $arguments): array {
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

  // ---- Tool: get_page_tree -------------------------------------------------

  /**
   * One element tree with the stable content hash the package variant has.
   */
  protected function doGetPageTree(int $root_id, string $langcode): array {
    if ($root_id <= 0) {
      return ['status' => 'error', 'error' => 'pagedesigner_root_id is required.'];
    }
    if ($langcode === '') {
      $langcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
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

  // ---- Tool: get_entities ----------------------------------------------

  /**
   * Plain (non-Pagedesigner) entity payloads, optionally filtered by key.
   */
  protected function doGetEntities(array $arguments): array {
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

  /**
   * Wrap a PHP array as an MCP `text` response carrying JSON.
   */
  protected function jsonResponse(array $data): array {
    return [
      [
        'type' => 'text',
        'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ],
    ];
  }

}

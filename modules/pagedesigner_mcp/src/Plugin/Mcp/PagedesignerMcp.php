<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Plugin\Mcp;

use Drupal\pagedesigner_mcp\Service\PagedesignerMcpOperations;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp\Attribute\Mcp;
use Drupal\mcp\Plugin\McpPluginBase;
use Drupal\mcp\ServerFeatures\Tool;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Legacy drupal/mcp adapter over PagedesignerMcpOperations — transition only.
 *
 * The module's primary transport is drupal/mcp_server (src/Plugin/Tool/,
 * OAuth 2.1 Bearer). This plugin keeps the old /mcp/post endpoint working
 * while fleet sites migrate; it is removed together with the drupal/mcp
 * dependency once the transition window closes.
 */
#[Mcp(
  id: 'pagedesigner-mcp',
  name: new TranslatableMarkup('Pagedesigner MCP'),
  description: new TranslatableMarkup('Read-only Pagedesigner migration export tools (manifest, page trees, taxonomies, menus, plain entities) for the iqual ai-platform content-migrator agent.'),
)]
class PagedesignerMcp extends McpPluginBase implements ContainerFactoryPluginInterface {

  protected PagedesignerMcpOperations $operations;

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
    $instance->operations = $container->get('pagedesigner_mcp.operations');
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
        name: 'get_users',
        description: 'Export the site accounts (uid, uuid, name, mail, status, roles, created) and its roles (id, label). Same shape as users.json of the drush package. A page carries its owner as {uid, name} only; the target matches an owner by e-mail, so it resolves those uids against this list. Users import BEFORE nodes on the target.',
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
    foreach ([
      'get_migration_manifest', 'get_page_tree', 'get_taxonomies',
      'get_menus', 'get_users', 'get_entities',
    ] as $known) {
      if ($toolId === $known || $toolId === md5($known)) {
        return $this->jsonResponse($this->operations->execute($known, $arguments));
      }
    }
    throw new \InvalidArgumentException('pagedesigner_mcp: unknown tool id ' . $toolId);
  }

  /**
   * {@inheritdoc}
   */
  public function hasAccess(): AccessResult {
    return AccessResult::allowedIfHasPermission($this->currentUser, 'use pagedesigner_mcp tools');
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

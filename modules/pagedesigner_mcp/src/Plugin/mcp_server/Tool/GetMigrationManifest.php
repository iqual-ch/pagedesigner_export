<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Plugin\mcp_server\Tool;

use Drupal\pagedesigner_mcp\Service\PagedesignerMcpOperations;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP tool: get_migration_manifest — thin adapter over PagedesignerMcpOperations.
 */
#[Tool(
  id: 'get_migration_manifest',
  label: new TranslatableMarkup('Get migration manifest'),
  description: new TranslatableMarkup('Return the migration manifest: schema/source info plus one entry per Pagedesigner page (bundle, uuid, langcodes, titles, paths, publication state, created/changed, author) and a step-3 content summary (taxonomy/menu counts, plain entities). Same shape as the manifest.json of a drush pd:migration-export package, without per-page content hashes (fetch trees for those). A large site must page it with `batch_size` / `after_id`: unpaged, every entity is loaded in one request.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'bundle' => ['type' => 'string', 'description' => 'Optional node bundle filter (e.g. "page").'],
      'limit' => ['type' => 'integer', 'description' => 'Optional cap on the number of Pagedesigner pages.'],
      'include_unpublished' => ['type' => 'boolean', 'description' => 'Include unpublished page translations.', 'default' => FALSE],
      'batch_size' => ['type' => 'integer', 'description' => 'Optional: page the manifest. Returns the pages and plain entities (`content.entities`) among the next `batch_size` entities by id after `after_id`, plus `paging: {after_id, next_after_id, scanned, total}`; `next_after_id` is null on the last slice. `limit` does not apply, and the step-3 counts are left out.'],
      'after_id' => ['type' => 'integer', 'description' => 'Paged form: the previous slice\'s `paging.next_after_id` (0 for the first).', 'default' => 0],
    ],
    'required' => [],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetMigrationManifest extends ToolPluginBase {

  protected PagedesignerMcpOperations $operations;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
    );
    $instance->operations = $container->get('pagedesigner_mcp.operations');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Enabled by default: the module exists solely to expose these tools.
   */
  protected function defaultConfiguration(): array {
    return ['enabled' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    return $this->operations->execute('get_migration_manifest', $arguments);
  }

}

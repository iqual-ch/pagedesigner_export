<?php

declare(strict_types=1);

namespace Drupal\pagedesigner_mcp\Plugin\Tool;

use Drupal\pagedesigner_mcp\Service\PagedesignerMcpOperations;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\Tool;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Mcp\Server\ClientGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MCP tool: get_entities — thin adapter over PagedesignerMcpOperations.
 */
#[Tool(
  id: 'get_entities',
  label: new TranslatableMarkup('Get entities'),
  description: new TranslatableMarkup('Export content entities WITHOUT Pagedesigner roots (news, FAQs, ...): per-translation fields with media enrichment, one payload per entity. Same shape as the entities/*.json files of the drush package. Optionally filter by entity keys ("node:45").'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'entity_keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional list of "entity_type:id" keys to export; omit for all.'],
      'bundle' => ['type' => 'string', 'description' => 'Optional bundle filter.'],
      'include_unpublished' => ['type' => 'boolean', 'description' => 'Include unpublished entities.', 'default' => FALSE],
    ],
    'required' => [],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class GetEntities extends ToolPluginBase {

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
    return $this->operations->execute('get_entities', $arguments);
  }

}
